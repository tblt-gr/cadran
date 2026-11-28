<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalReason;

/** What a definition asks of one period: its target, the margin around it and why it has none. */
final readonly class GoalTarget
{
    public function __construct(
        public ?string $target = null,
        public ?string $tolerance = null,
        public ?GoalReason $reason = null,
        public ?string $income = null,
        public ?ReferenceIncomeView $reference = null,
    ) {
    }
}
