<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * A planned expense a refund of the same month may reduce. It tracks what is
 * left to refund so the plan respects the same cap as the refund use case:
 * the sum of the refunds of an expense never exceeds its amount.
 */
final class RefundCandidate
{
    private DecimalValue $remaining;

    public function __construct(
        public readonly string $key,
        public readonly string $categoryId,
        public readonly string $lineId,
        public readonly DecimalValue $original,
    ) {
        $this->remaining = $original;
    }

    public function canAbsorb(DecimalValue $magnitude): bool
    {
        return $this->remaining->compareTo($magnitude) >= 0;
    }

    public function consume(DecimalValue $magnitude): void
    {
        $this->remaining = ExactDecimal::subtract($this->remaining, $magnitude);
    }
}
