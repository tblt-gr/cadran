<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class GoalStatusMonthView
{
    public function __construct(
        public string $month,
        public string $state,
        public ?string $value,
        public ?string $expectedPart,
        public ?string $cumulativeValue,
        public ?string $cumulativeExpected,
        public ?int $goalVersion,
        public ?int $policyVersion,
    ) {
    }
}
