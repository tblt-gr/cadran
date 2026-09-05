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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adapts HTTP to the category read and edit use cases. Field-level validation
 * lives in {@see CategoryPayload}, the wire shape in
 * {@see CategoryRepresentation} and the envelope in
 * {@see CategoryHttpEnvelope}; what remains here is the routing and the status
 * mapping. The lifecycle operations have their own controller.
 */
final readonly class CategoryController
{
    private const array CREATE_FIELDS = [
        'type', 'label', 'parentId', 'icon', 'color', 'defaultAnalyticAxes', 'budgetIncluded', 'sortOrder',
    ];
    private const array UPDATE_FIELDS = [
        'type', 'label', 'icon', 'color', 'defaultAnalyticAxes', 'budgetIncluded', 'sortOrder', 'version',
    ];

    public function __construct(private CategoryHttpEnvelope $envelope)
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
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_category_query');
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
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_category_query');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return $this->envelope->json(CategoryRepresentation::page($categories));
    }

    #[Route('/api/v1/categories', name: 'api_v1_categories_create', methods: ['POST'])]
    public function create(Request $request, CreateCategory $createCategory): Response
    {
        $body = $this->envelope->body($request);
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
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return $this->envelope->json(CategoryRepresentation::one($category), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/categories/{id}', name: 'api_v1_categories_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateCategory $updateCategory): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        }

        $body = $this->envelope->body($request);
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
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        } catch (CategoryConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return $this->envelope->json(CategoryRepresentation::one($category));
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }
}
