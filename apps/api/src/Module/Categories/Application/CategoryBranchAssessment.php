<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryTree;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The blockers and projected depths specific to moving, merging or redirecting a category branch.
 * Split from {@see AssessCategoryImpact}, which keeps the assessment shared by every operation.
 */
final readonly class CategoryBranchAssessment
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryReplacementRepository $replacements,
    ) {
    }

    /**
     * @param list<Category>              $descendants
     * @param list<CategoryImpactBlocker> $blockers
     *
     * @return array{list<CategoryImpactBlocker>, int, array<string, int>}
     */
    public function move(
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
    public function merge(
        WorkspaceScope $workspace,
        Category $source,
        ?Category $target,
        array $descendants,
        array $children,
        array $branchIds,
        array $blockers,
    ): array {
        $blockers = $this->redirection($workspace, $source, $target, $blockers);
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
    public function redirection(
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
}
