<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalVersionHistoryItemView
{
    /** @param list<GoalVersionChangeView> $changes */
    public function __construct(
        public GoalVersionView $definition,
        public bool $retroactive,
        public ?string $reason,
        public \DateTimeImmutable $createdAt,
        public string $createdById,
        public string $createdByDisplayName,
        public array $changes,
    ) {
    }
}
