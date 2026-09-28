<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final readonly class UpdateGoalInput
{
    public function __construct(
        public string $goalId,
        public int $expectedVersion,
        public string $label,
        public ?string $targetAmount,
        public ?string $targetAssetCode,
        public ?string $ratio,
        public bool $identitySubmitted,
    ) {
    }
}
