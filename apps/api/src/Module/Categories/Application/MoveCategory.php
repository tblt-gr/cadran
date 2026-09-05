<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\InvalidCategory;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reparents a category and the whole branch below it.
 *
 * The branch is locked, assessed and rewritten inside one transaction: a move
 * that reached the database halfway would leave descendants pointing at a depth
 * their parent no longer has.
 */
final readonly class MoveCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryRepository $categories,
        private AssessCategoryImpact $assessImpact,
        private PresentCategory $presentCategory,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, ?string $parentId, int $expectedVersion): CategoryView
    {
        $context = $this->caller->resolveContext();
        $parentId = CategoryInputParser::optionalIdentifier($parentId);

        return $this->transactionBoundary->transactional(function () use ($id, $parentId, $expectedVersion, $context): CategoryView {
            $impact = ($this->assessImpact)(
                $context->workspace,
                CategoryLifecycleOperation::MOVE,
                $id,
                $parentId,
                null,
                lock: true,
            );
            $current = $impact->source;
            if ($expectedVersion !== $current->version) {
                throw new CategoryConflict('The category was changed by another request.');
            }
            if (!$impact->isAllowed()) {
                throw new CategoryOperationRefused($impact->blockers);
            }

            $now = $this->clock->now();
            $newDepth = null === $impact->target ? 1 : $impact->target->depth + 1;
            try {
                $moved = $current->moveTo($impact->target?->id, $newDepth, $now);
            } catch (InvalidCategory $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            $this->store($moved, $current->version);
            // Breadth-first, so a descendant is only written once its parent already
            // carries the depth the database trigger will compare it against.
            foreach ($impact->descendants as $descendant) {
                $depth = $impact->rebasedDepths[$descendant->id] ?? null;
                if (null === $depth || $depth === $descendant->depth) {
                    continue;
                }

                $this->store(
                    $descendant->followAncestorMove($descendant->parentId, $depth, $now),
                    $descendant->version,
                );
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::MOVED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $moved->id,
                diff: AuditDiff::change(
                    [...CategoryAuditFingerprint::of($current), 'movedDescendants' => 0],
                    [...CategoryAuditFingerprint::of($moved), 'movedDescendants' => $impact->descendantCount()],
                ),
            ));

            return ($this->presentCategory)($context->workspace, $moved);
        });
    }

    private function store(Category $category, int $expectedVersion): void
    {
        if (!$this->categories->update($category, $expectedVersion)) {
            throw new CategoryConflict('The category was changed by another request.');
        }
    }
}
