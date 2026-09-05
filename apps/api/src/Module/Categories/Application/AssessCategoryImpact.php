<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryTree;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Decides what a lifecycle operation changes and whether it may proceed.
 *
 * Preview and execution share this one assessment on purpose: a confirmation
 * screen that describes different consequences from the write it leads to is
 * the failure this class exists to prevent. Reading it with `$lock` set takes
 * the row locks the write needs, so nothing moves between the assessment and
 * the update it authorises.
 */
final readonly class AssessCategoryImpact
{
    public const string CLASSIFICATIONS_UNAVAILABLE = 'TRANSACTIONS_UNAVAILABLE';

    public function __construct(
        private CategoryRepository $categories,
        private CategoryReplacementRepository $replacements,
    ) {
    }

    public function __invoke(
        WorkspaceScope $workspace,
        CategoryLifecycleOperation $operation,
        string $categoryId,
        ?string $targetId,
        ?\DateTimeImmutable $effectiveFrom,
        bool $lock,
    ): CategoryImpact {
        $source = $lock
            ? $this->categories->findForUpdate($workspace, $categoryId)
            : $this->categories->find($workspace, $categoryId);
        if (null === $source) {
            throw new CategoryNotFound();
        }

        $descendants = $lock
            ? $this->categories->descendantsForUpdate($workspace, $categoryId)
            : $this->categories->descendants($workspace, $categoryId);
        $branchIds = array_flip(array_column($descendants, 'id'));

        $target = null;
        $blockers = [];
        if (null !== $targetId) {
            if ($targetId === $categoryId) {
                $blockers[] = CategoryImpactBlocker::TARGET_IS_SELF;
            } else {
                $target = $lock
                    ? $this->categories->findForUpdate($workspace, $targetId)
                    : $this->categories->find($workspace, $targetId);
                if (null === $target) {
                    $blockers[] = CategoryImpactBlocker::TARGET_NOT_FOUND;
                } else {
                    if (null !== $target->archivedAt) {
                        $blockers[] = CategoryImpactBlocker::TARGET_ARCHIVED;
                    }
                    if ($target->type !== $source->type) {
                        $blockers[] = CategoryImpactBlocker::TARGET_TYPE_MISMATCH;
                    }
                }
            }
        } elseif (CategoryLifecycleOperation::MOVE !== $operation && CategoryLifecycleOperation::ARCHIVE !== $operation) {
            $blockers[] = CategoryImpactBlocker::TARGET_MISSING;
        }

        if (null !== $source->archivedAt) {
            $blockers[] = CategoryImpactBlocker::SOURCE_ARCHIVED;
        }

        $resultingDepth = CategoryTree::resultingDepth($source->id, $source->depth, $descendants);
        $reparentedChildren = [];
        $rebasedDepths = [];
        // Retiring a category that others redirect into would leave those redirections
        // naming a category nobody can select, which the database asserts must never
        // happen. Both operations that retire a source therefore look at them.
        $incoming = in_array(
            $operation,
            [CategoryLifecycleOperation::ARCHIVE, CategoryLifecycleOperation::MERGE],
            true,
        ) ? $this->replacements->findByTarget($workspace, $categoryId, $lock) : [];

        switch ($operation) {
            case CategoryLifecycleOperation::MOVE:
                [$blockers, $resultingDepth, $rebasedDepths] = $this->assessMove(
                    $workspace,
                    $source,
                    $targetId,
                    $target,
                    $descendants,
                    $blockers,
                );
                break;

            case CategoryLifecycleOperation::MERGE:
                $reparentedChildren = array_values(array_filter(
                    $descendants,
                    static fn (Category $descendant): bool => $descendant->parentId === $source->id,
                ));
                [$blockers, $resultingDepth, $rebasedDepths] = $this->assessMerge(
                    $workspace,
                    $source,
                    $target,
                    $descendants,
                    $reparentedChildren,
                    $branchIds,
                    $blockers,
                );
                // The merge carries the incoming redirections over to the target, so they
                // must be able to point there without closing a loop.
                foreach ($incoming as $redirection) {
                    if (null !== $target && ($redirection->sourceCategoryId === $target->id
                        || in_array($redirection->sourceCategoryId, $this->replacements->chainFrom($workspace, $target->id), true))) {
                        $blockers[] = CategoryImpactBlocker::REPLACEMENT_CYCLE;
                    }
                }
                break;

            case CategoryLifecycleOperation::REPLACE:
                $blockers = $this->assessRedirection($workspace, $source, $target, $blockers);
                break;

            case CategoryLifecycleOperation::ARCHIVE:
                if ([] !== $incoming) {
                    // Archiving names no successor, so there is nowhere to carry them to.
                    $blockers[] = CategoryImpactBlocker::REDIRECTION_TARGET;
                }
                foreach ($descendants as $descendant) {
                    if ($descendant->parentId === $source->id && null === $descendant->archivedAt) {
                        $blockers[] = CategoryImpactBlocker::ACTIVE_CHILDREN;
                        break;
                    }
                }
                break;
        }

        $archivesSource = in_array(
            $operation,
            [CategoryLifecycleOperation::ARCHIVE, CategoryLifecycleOperation::MERGE],
            true,
        );

        return new CategoryImpact(
            operation: $operation,
            source: $source,
            target: $target,
            effectiveFrom: $effectiveFrom,
            resultingDepth: $resultingDepth,
            archivesSource: $archivesSource,
            redirectsHistory: in_array(
                $operation,
                [CategoryLifecycleOperation::MERGE, CategoryLifecycleOperation::REPLACE],
                true,
            ),
            blockers: self::distinct($blockers),
            descendants: $descendants,
            incomingRedirections: $incoming,
            reparentedChildren: $reparentedChildren,
            rebasedDepths: $rebasedDepths,
            // Transactions do not exist yet, so nothing can count the classifications a
            // redirection would move. TX-001 replaces the reason with the count.
            affectedClassifications: null,
            affectedClassificationsReason: self::CLASSIFICATIONS_UNAVAILABLE,
        );
    }

    /**
     * @param list<Category>              $descendants
     * @param list<CategoryImpactBlocker> $blockers
     *
     * @return array{list<CategoryImpactBlocker>, int, array<string, int>}
     */
    private function assessMove(
        WorkspaceScope $workspace,
        Category $source,
        ?string $targetId,
        ?Category $target,
        array $descendants,
        array $blockers,
    ): array {
        if (null !== $targetId && null !== $target && Category::wouldCycle(
            $source->id,
            $target->id,
            fn (string $candidate): ?string => $this->categories->find($workspace, $candidate)?->parentId,
        )) {
            $blockers[] = CategoryImpactBlocker::CYCLE;
        }

        $newDepth = null === $target ? 1 : $target->depth + 1;
        $resultingDepth = CategoryTree::resultingDepth($source->id, $newDepth, $descendants);
        if ($resultingDepth > Category::MAX_TREE_DEPTH) {
            $blockers[] = CategoryImpactBlocker::DEPTH_EXCEEDED;
        }

        if (null === $source->archivedAt && $this->categories->hasActiveSiblingLabel(
            $workspace,
            $source->type,
            $target?->id,
            $source->label,
            [$source->id],
        )) {
            $blockers[] = CategoryImpactBlocker::SIBLING_LABEL_CONFLICT;
        }

        return [$blockers, $resultingDepth, CategoryTree::projectedDepths($source->id, $newDepth, $descendants)];
    }

    /**
     * @param list<Category>              $descendants
     * @param list<Category>              $children
     * @param array<string, int>          $branchIds
     * @param list<CategoryImpactBlocker> $blockers
     *
     * @return array{list<CategoryImpactBlocker>, int, array<string, int>}
     */
    private function assessMerge(
        WorkspaceScope $workspace,
        Category $source,
        ?Category $target,
        array $descendants,
        array $children,
        array $branchIds,
        array $blockers,
    ): array {
        $blockers = $this->assessRedirection($workspace, $source, $target, $blockers);
        if (null === $target) {
            return [$blockers, CategoryTree::resultingDepth($source->id, $source->depth, $descendants), []];
        }

        if (isset($branchIds[$target->id])) {
            // Reparenting the branch under one of its own members would strand the rest of it.
            $blockers[] = CategoryImpactBlocker::TARGET_IS_DESCENDANT;

            return [$blockers, CategoryTree::resultingDepth($source->id, $source->depth, $descendants), []];
        }

        $childDepth = $target->depth + 1;
        $resultingDepth = $target->depth;
        $rebasedDepths = [];
        foreach ($children as $child) {
            $rebasedDepths[$child->id] = $childDepth;
            $rebasedDepths += CategoryTree::projectedDepths($child->id, $childDepth, $descendants);
            $resultingDepth = max($resultingDepth, CategoryTree::resultingDepth($child->id, $childDepth, $descendants));

            if (null === $child->archivedAt && $this->categories->hasActiveSiblingLabel(
                $workspace,
                $child->type,
                $target->id,
                $child->label,
                // The source is archived by this same merge, so it no longer occupies the
                // label: a child folded into its own parent must not collide with it.
                [$child->id, $source->id],
            )) {
                $blockers[] = CategoryImpactBlocker::SIBLING_LABEL_CONFLICT;
            }
        }

        if ($resultingDepth > Category::MAX_TREE_DEPTH) {
            $blockers[] = CategoryImpactBlocker::DEPTH_EXCEEDED;
        }

        return [$blockers, $resultingDepth, $rebasedDepths];
    }

    /**
     * @param list<CategoryImpactBlocker> $blockers
     *
     * @return list<CategoryImpactBlocker>
     */
    private function assessRedirection(
        WorkspaceScope $workspace,
        Category $source,
        ?Category $target,
        array $blockers,
    ): array {
        if (null !== $this->replacements->findBySource($workspace, $source->id)) {
            $blockers[] = CategoryImpactBlocker::ALREADY_REDIRECTED;
        }

        if (null !== $target) {
            $chain = $this->replacements->chainFrom($workspace, $target->id);
            if (in_array($source->id, $chain, true)) {
                $blockers[] = CategoryImpactBlocker::REPLACEMENT_CYCLE;
            }
            if (count($chain) >= CategoryReplacementRepository::MAX_CHAIN_LENGTH) {
                $blockers[] = CategoryImpactBlocker::REPLACEMENT_CHAIN_TOO_LONG;
            }
        }

        return $blockers;
    }

    /**
     * @param list<CategoryImpactBlocker> $blockers
     *
     * @return list<CategoryImpactBlocker>
     */
    private static function distinct(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }

        return array_values($unique);
    }
}
