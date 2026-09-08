<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryReplacement;

/**
 * What a lifecycle operation would change, and why it would be refused.
 *
 * The same assessment answers the preview endpoint and gates the operation
 * itself, so a confirmation screen and the write it leads to can never disagree
 * about the consequences. It carries the locked rows the write needs alongside
 * the counts the preview publishes.
 */
final readonly class CategoryImpact
{
    /**
     * @param list<CategoryImpactBlocker> $blockers
     * @param list<Category>              $descendants          every category below the source, breadth first
     * @param list<CategoryReplacement>   $incomingRedirections redirections naming the source as their target
     * @param list<Category>              $reparentedChildren   the direct children a merge moves under the target
     * @param array<string, int>          $rebasedDepths        the depth each descendant takes afterwards
     *
     * `$affectedClassifications` counts the historical classifications the operation would
     * re-point, and is null when that count cannot be established. A zero would tell a reader
     * that nothing in their history moves, which is a claim this release cannot make.
     */
    public function __construct(
        public CategoryLifecycleOperation $operation,
        public Category $source,
        public ?Category $target,
        public ?\DateTimeImmutable $effectiveFrom,
        public int $resultingDepth,
        public bool $archivesSource,
        public bool $redirectsHistory,
        public array $blockers,
        public array $descendants,
        public array $incomingRedirections,
        public array $reparentedChildren,
        public array $rebasedDepths,
        public ?int $affectedClassifications,
        public ?string $affectedClassificationsReason,
    ) {
    }

    public function isAllowed(): bool
    {
        return [] === $this->blockers;
    }

    public function descendantCount(): int
    {
        return count($this->descendants);
    }

    public function archivedDescendantCount(): int
    {
        return count(array_filter(
            $this->descendants,
            static fn (Category $descendant): bool => null !== $descendant->archivedAt,
        ));
    }

    public function incomingRedirectionCount(): int
    {
        return count($this->incomingRedirections);
    }

    public function reparentedChildCount(): int
    {
        return count($this->reparentedChildren);
    }
}
