<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\ArchiveCategory;
use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Application\CategoryNotFound;
use App\Module\Categories\Application\CategoryOperationRefused;
use App\Module\Categories\Application\InvalidCategoryInput;
use App\Module\Categories\Application\MergeCategory;
use App\Module\Categories\Application\MoveCategory;
use App\Module\Categories\Application\PreviewCategoryImpact;
use App\Module\Categories\Application\ReplaceCategory;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Doctrine\DBAL\Exception\RetryableException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The operations that change how history reads: moving a branch, folding a
 * category into another, retiring one, and replacing one from a date onwards.
 *
 * Each takes the version the client last saw, so an operation confirmed against
 * a stale preview is refused rather than applied to a category that has since
 * changed. A refusal names its blockers as RFC 9457 extension members so the
 * interface can explain every reason at once.
 */
final readonly class CategoryLifecycleController
{
    private const array MOVE_FIELDS = ['parentId', 'version'];
    private const array MERGE_FIELDS = ['targetId', 'version'];
    private const array ARCHIVE_FIELDS = ['version'];
    private const array REPLACE_FIELDS = ['targetId', 'effectiveFrom', 'version'];

    public function __construct(private CategoryHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/categories/{id}/impact', name: 'api_v1_categories_impact', methods: ['GET'])]
    public function impact(string $id, Request $request, PreviewCategoryImpact $previewImpact): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        }

        $targetId = $request->query->getString('targetId');
        $effectiveFrom = $request->query->getString('effectiveFrom');

        try {
            $impact = $previewImpact(
                $id,
                $request->query->getString('operation'),
                '' === $targetId ? null : $targetId,
                '' === $effectiveFrom ? null : $effectiveFrom,
            );
        } catch (InvalidCategoryInput) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_category_query');
        } catch (CategoryNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return $this->envelope->json(CategoryRepresentation::impact($impact));
    }

    #[Route('/api/v1/categories/{id}/move', name: 'api_v1_categories_move', methods: ['POST'])]
    public function move(string $id, Request $request, MoveCategory $moveCategory): Response
    {
        return $this->operate($id, $request, self::MOVE_FIELDS, static fn (CategoryPayload $payload) => $moveCategory(
            $id,
            $payload->nullableString('parentId'),
            $payload->integer('version'),
        ));
    }

    #[Route('/api/v1/categories/{id}/merge', name: 'api_v1_categories_merge', methods: ['POST'])]
    public function merge(string $id, Request $request, MergeCategory $mergeCategory): Response
    {
        return $this->operate($id, $request, self::MERGE_FIELDS, static fn (CategoryPayload $payload) => $mergeCategory(
            $id,
            $payload->string('targetId'),
            $payload->integer('version'),
        ));
    }

    #[Route('/api/v1/categories/{id}/archive', name: 'api_v1_categories_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveCategory $archiveCategory): Response
    {
        return $this->operate($id, $request, self::ARCHIVE_FIELDS, static fn (CategoryPayload $payload) => $archiveCategory(
            $id,
            $payload->integer('version'),
        ));
    }

    #[Route('/api/v1/categories/{id}/replacement', name: 'api_v1_categories_replace', methods: ['POST'])]
    public function replace(string $id, Request $request, ReplaceCategory $replaceCategory): Response
    {
        return $this->operate($id, $request, self::REPLACE_FIELDS, static fn (CategoryPayload $payload) => $replaceCategory(
            $id,
            $payload->string('targetId'),
            $payload->string('effectiveFrom'),
            $payload->integer('version'),
        ));
    }

    /**
     * @param list<string>                                                               $fields
     * @param callable(CategoryPayload): \App\Module\Categories\Application\CategoryView $operation
     */
    private function operate(string $id, Request $request, array $fields, callable $operation): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        }

        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $category = $operation(CategoryPayload::of($body, $fields));
        } catch (CategoryOperationRefused $refusal) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.category_operation_refused',
                CategoryHttpEnvelope::TYPE_OPERATION_REFUSED,
                ['blockers' => array_map(static fn ($blocker): string => $blocker->value, $refusal->blockers)],
            );
        } catch (InvalidCategoryInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');
        } catch (CategoryNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        } catch (CategoryConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.category_conflict');
        } catch (RetryableException) {
            // Two lifecycle operations on overlapping branches lock their rows in the
            // order their own request implies, so PostgreSQL can break the tie. That is a
            // concurrency outcome the client should retry, not a server fault.
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.category_concurrent_operation');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden');
        }

        return $this->envelope->json(CategoryRepresentation::one($category));
    }
}
