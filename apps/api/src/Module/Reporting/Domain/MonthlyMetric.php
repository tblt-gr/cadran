<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyMetric
{
    /** @param list<string> $sourceTransactionIds */
    private function __construct(
        public ?DecimalValue $value,
        public ?AssetCode $asset,
        public ?MonthlyProjectionReason $reason,
        public array $sourceTransactionIds,
        public int $pendingCount,
    ) {
        if ((null === $value) === (null === $reason)) {
            throw new \InvalidArgumentException('A monthly metric carries either a value or a reason.');
        }
    }

    /** @param list<string> $sourceTransactionIds */
    public static function value(DecimalValue $value, ?AssetCode $asset = null, array $sourceTransactionIds = [], int $pendingCount = 0): self
    {
        return new self($value, $asset, null, $sourceTransactionIds, $pendingCount);
    }

    /** @param list<string> $sourceTransactionIds */
    public static function missing(MonthlyProjectionReason $reason, array $sourceTransactionIds = [], int $pendingCount = 0): self
    {
        return new self(null, null, $reason, $sourceTransactionIds, $pendingCount);
    }
}
