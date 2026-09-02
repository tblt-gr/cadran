<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Application\CategoryNotFound;
use App\Module\Categories\Application\CreateCategory;
use App\Module\Categories\Application\CreateCategoryInput;
use App\Module\Categories\Application\InvalidCategoryInput;
use App\Module\Categories\Application\ListCategories;
use App\Module\Categories\Application\UpdateCategory;
use App\Module\Categories\Application\UpdateCategoryInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to the category use cases. Field-level validation lives in
 * {@see CategoryPayload} and the wire shape in {@see CategoryRepresentation};
 * what remains here is the envelope, the routing and the status mapping.
 */
final readonly class CategoryController
{
    private const int MAX_BODY_BYTES = 16_384;
    private const array CREATE_FIELDS = [
        'type', 'label', 'parentId', 'icon', 'color', 'defaultAnalyticAxes', 'budgetIncluded', 'sortOrder',
    ];
    private const array UPDATE_FIELDS = [
        'type', 'label', 'icon', 'color', 'defaultAnalyticAxes', 'budgetIncluded', 'sortOrder', 'version',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/categories', name: 'api_v1_categories_list', methods: ['GET'])]
    public function list(Request $request, ListCategories $listCategories): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        $includeArchived = $request->query->getString('includeArchived');
        $type = $request->query->getString('type');
        $search = $request->query->getString('search');
        $parentEligible = $request->query->getString('parentEligible');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage)
            || !in_array($includeArchived, ['', 'true', 'false'], true)
            || !in_array($type, ['', 'EXPENSE', 'INCOME'], true)
            || !in_array($parentEligible, ['', 'true', 'false'], true)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_category_query');
        }

        try {
            $categories = $listCategories(
                'true' === $includeArchived,
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
                '' === $type ? null : $type,
                '' === $search ? null : $search,
                'true' === $parentEligible,
            );
        } catch (InvalidCategoryInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_category_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return self::json(CategoryRepresentation::page($categories));
    }

    #[Route('/api/v1/categories', name: 'api_v1_categories_create', methods: ['POST'])]
    public function create(Request $request, CreateCategory $createCategory): Response
    {
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = CategoryPayload::of($body, self::CREATE_FIELDS);
            $input = new CreateCategoryInput(
                type: $payload->string('type'),
                label: $payload->string('label'),
                parentId: $payload->nullableString('parentId'),
                icon: $payload->nullableString('icon'),
                color: $payload->nullableString('color'),
                defaultAnalyticAxes: $payload->stringList('defaultAnalyticAxes'),
                budgetIncluded: $payload->boolean('budgetIncluded'),
                sortOrder: $payload->integer('sortOrder'),
            );
            $category = $createCategory($input);
        } catch (InvalidCategoryInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return self::json(CategoryRepresentation::one($category), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/categories/{id}', name: 'api_v1_categories_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateCategory $updateCategory): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = CategoryPayload::of($body, self::UPDATE_FIELDS);
            $input = new UpdateCategoryInput(
                type: $payload->string('type'),
                label: $payload->string('label'),
                icon: $payload->nullableString('icon'),
                color: $payload->nullableString('color'),
                defaultAnalyticAxes: $payload->stringList('defaultAnalyticAxes'),
                budgetIncluded: $payload->boolean('budgetIncluded'),
                sortOrder: $payload->integer('sortOrder'),
                version: $payload->integer('version'),
            );
            $category = $updateCategory($id, $input);
        } catch (InvalidCategoryInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        } catch (CategoryConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return self::json(CategoryRepresentation::one($category));
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
    private function readBody(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.category_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
        );
    }
}
