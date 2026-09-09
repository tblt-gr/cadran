<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\Category;

final readonly class CategoryImpactView
{
    /** @param list<string> $blockers */
    public function __construct(
        public string $operation,
        public string $categoryId,
        public ?string $targetId,
        public ?string $targetLabel,
        public ?string $effectiveFrom,
        public int $descendantCount,
        public int $archivedDescendantCount,
        public int $reparentedChildCount,
        public int $incomingRedirectionCount,
        public int $resultingDepth,
        public int $maximumDepth,
        public bool $archivesSource,
        public bool $redirectsHistory,
        public bool $allowed,
        public array $blockers,
        public ?int $affectedClassifications,
        public ?string $affectedClassificationsReason,
    ) {
    }

    public static function of(CategoryImpact $impact): self
    {
        return new self(
            operation: $impact->operation->value,
            categoryId: $impact->source->id,
            targetId: $impact->target?->id,
            targetLabel: $impact->target?->label,
            effectiveFrom: $impact->effectiveFrom?->format('Y-m-d'),
            descendantCount: $impact->descendantCount(),
            archivedDescendantCount: $impact->archivedDescendantCount(),
            reparentedChildCount: $impact->reparentedChildCount(),
            incomingRedirectionCount: $impact->incomingRedirectionCount(),
            resultingDepth: $impact->resultingDepth,
            maximumDepth: Category::MAX_TREE_DEPTH,
            archivesSource: $impact->archivesSource,
            redirectsHistory: $impact->redirectsHistory,
            allowed: $impact->isAllowed(),
            blockers: array_map(
                static fn (CategoryImpactBlocker $blocker): string => $blocker->value,
                $impact->blockers,
            ),
            affectedClassifications: $impact->affectedClassifications,
            affectedClassificationsReason: $impact->affectedClassificationsReason,
        );
    }
}
