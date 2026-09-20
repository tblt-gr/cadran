<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryReplacement;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\InvalidCategory;
use App\Module\Categories\Domain\InvalidCategoryReplacement;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Folds a category into another one.
 *
 * The merge does three things that must succeed or fail together: it moves the
 * direct children under the target, it records the redirection that makes the
 * history of the source read as the target's, and it archives the source. A
 * partial merge would leave a branch attached to a category nobody can select.
 */
final readonly class MergeCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryRepository $categories,
        private CategoryReplacementRepository $replacements,
        private AssessCategoryImpact $assessImpact,
        private AssertPeriodOpen $periods,
        private PresentCategory $presentCategory,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
        private CategoryArchivalSideEffect $archivalSideEffect,
    ) {
    }

    public function __invoke(string $id, ?string $targetId, int $expectedVersion): CategoryView
    {
        $context = $this->caller->resolveContext();
        $targetId = CategoryInputParser::optionalIdentifier($targetId);

        return $this->transactionBoundary->transactional(function () use ($id, $targetId, $expectedVersion, $context): CategoryView {
            $this->periods->assertNoActiveClosure($context->workspace);
            $this->archivalSideEffect->lockWorkspace($context->workspace);
            $impact = ($this->assessImpact)(
                $context->workspace,
                CategoryLifecycleOperation::MERGE,
                $id,
                $targetId,
                null,
                lock: true,
            );
            $current = $impact->source;
            if ($expectedVersion !== $current->version) {
                throw new CategoryConflict('The category was changed by another request.');
            }
            if (!$impact->isAllowed() || null === $impact->target) {
                throw new CategoryOperationRefused($impact->blockers);
            }

            $target = $impact->target;
            $now = $this->clock->now();

            try {
                $archived = $current->archive($now);
                $replacement = CategoryReplacement::merge(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    sourceCategoryId: $current->id,
                    targetCategoryId: $target->id,
                    createdAt: $now,
                );
            } catch (InvalidCategory|InvalidCategoryReplacement $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            // The source leaves the active sibling index before its children take its
            // place under the target. The other order makes a child folded into its own
            // parent collide, at commit, with the parent this merge is archiving.
            $this->store($archived, $current->version);

            $reparented = array_flip(array_column($impact->reparentedChildren, 'id'));
            // Breadth-first: a descendant is written only once its parent already carries
            // the depth the database trigger will compare it against.
            foreach ($impact->descendants as $descendant) {
                $depth = $impact->rebasedDepths[$descendant->id] ?? $descendant->depth;
                $parentId = isset($reparented[$descendant->id]) ? $target->id : $descendant->parentId;
                if ($depth === $descendant->depth && $parentId === $descendant->parentId) {
                    continue;
                }

                $this->store($descendant->followAncestorMove($parentId, $depth, $now), $descendant->version);
            }

            $this->replacements->add($replacement);
            // A redirection that named the source now names the target: the merge moves
            // where that history is counted, so the redirection follows instead of being
            // left pointing at a category this transaction just archived.
            foreach ($impact->incomingRedirections as $incoming) {
                $this->replacements->repoint($incoming->repointTo($target->id));
            }

            $this->archivalSideEffect->apply($context->workspace, $archived->id, $context->actorId, $now);

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::MERGED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $archived->id,
                diff: AuditDiff::change(
                    [
                        ...CategoryAuditFingerprint::of($current),
                        'mergedInto' => null,
                        'movedChildren' => 0,
                        'repointedRedirections' => 0,
                    ],
                    [
                        ...CategoryAuditFingerprint::of($archived),
                        'mergedInto' => $target->id,
                        'movedChildren' => $impact->reparentedChildCount(),
                        'repointedRedirections' => $impact->incomingRedirectionCount(),
                    ],
                ),
            ));

            return ($this->presentCategory)($context->workspace, $archived);
        });
    }

    private function store(Category $category, int $expectedVersion): void
    {
        if (!$this->categories->update($category, $expectedVersion)) {
            throw new CategoryConflict('The category was changed by another request.');
        }
    }
}
