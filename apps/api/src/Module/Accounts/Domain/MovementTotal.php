<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class MovementTotal
{
    public function __construct(
        public ?DecimalValue $total,
        public int $bookedCount,
        public int $pendingCount,
        public bool $foreignAsset,
    ) {
        if ($bookedCount < 0 || $pendingCount < 0 || (0 < $bookedCount && null === $total)) {
            throw new \InvalidArgumentException('Movement totals require non-negative counts and a booked sum.');
        }
    }
}
