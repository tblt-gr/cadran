<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Application\CategoryNotFound;
use App\Module\Categories\Application\CategoryPage;
use App\Module\Categories\Application\CategoryView;
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

        return self::jsonPage($categories);
    }

    #[Route('/api/v1/categories', name: 'api_v1_categories_create', methods: ['POST'])]
    public function create(Request $request, CreateCategory $createCategory): Response
    {
        $data = $this->body($request, self::CREATE_FIELDS);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $input = new CreateCategoryInput(
                type: self::string($data, 'type'),
                label: self::string($data, 'label'),
                parentId: self::nullableString($data, 'parentId'),
                icon: self::nullableString($data, 'icon'),
                color: self::nullableString($data, 'color'),
                defaultAnalyticAxes: self::stringList($data, 'defaultAnalyticAxes'),
                budgetIncluded: self::boolean($data, 'budgetIncluded'),
                sortOrder: self::integer($data, 'sortOrder'),
            );
            $category = $createCategory($input);
        } catch (InvalidCategoryInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return self::json(self::represent($category), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/categories/{id}', name: 'api_v1_categories_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateCategory $updateCategory): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        }

        $data = $this->body($request, self::UPDATE_FIELDS);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $input = new UpdateCategoryInput(
                type: self::string($data, 'type'),
                label: self::string($data, 'label'),
                icon: self::nullableString($data, 'icon'),
                color: self::nullableString($data, 'color'),
                defaultAnalyticAxes: self::stringList($data, 'defaultAnalyticAxes'),
                budgetIncluded: self::boolean($data, 'budgetIncluded'),
                sortOrder: self::integer($data, 'sortOrder'),
                version: self::integer($data, 'version'),
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

        return self::json(self::represent($category));
    }

    /**
     * @param list<string> $allowedFields
     *
     * @return array<string, mixed>|Response
     */
    private function body(Request $request, array $allowedFields): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.category_payload_too_large');
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        $typed = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
            }
            $typed[$key] = $value;
        }

        $keys = array_keys($typed);
        sort($keys);
        $expected = $allowedFields;
        sort($expected);
        if ($keys !== $expected) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        }

        return $typed;
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
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \UnexpectedValueException();
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || (null !== $data[$key] && !is_string($data[$key]))) {
            throw new \UnexpectedValueException();
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new \UnexpectedValueException();
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new \UnexpectedValueException();
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \UnexpectedValueException();
        }
        if (count($data[$key]) > 6) {
            throw new \UnexpectedValueException();
        }

        foreach ($data[$key] as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException();
            }
        }

        return $data[$key];
    }

    private static function jsonPage(CategoryPage $page): JsonResponse
    {
        return self::json([
            'items' => array_map(self::represent(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ]);
    }

    /** @return array<string, mixed> */
    private static function represent(CategoryView $category): array
    {
        return [
            'id' => $category->id,
            'type' => $category->type,
            'label' => $category->label,
            'parentId' => $category->parentId,
            'parentLabel' => $category->parentLabel,
            'icon' => $category->icon,
            'color' => $category->color,
            'defaultAnalyticAxes' => $category->defaultAnalyticAxes,
            'budgetIncluded' => $category->budgetIncluded,
            'sortOrder' => $category->sortOrder,
            'depth' => $category->depth,
            'version' => $category->version,
            'used' => $category->used,
            'typeEditable' => $category->typeEditable,
            'typeEditReason' => $category->typeEditReason,
            'canAcceptChildren' => $category->canAcceptChildren,
            'archivedAt' => $category->archivedAt,
        ];
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
