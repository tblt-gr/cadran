<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyMetric
{
    private function __construct(
        public ?DecimalValue $value,
        public ?AssetCode $asset,
        public ?MonthlyProjectionReason $reason,
    ) {
        if ((null === $value) === (null === $reason)) {
            throw new \InvalidArgumentException('A monthly metric carries either a value or a reason.');
        }
    }

    public static function value(DecimalValue $value, ?AssetCode $asset = null): self
    {
        return new self($value, $asset, null);
    }

    public static function missing(MonthlyProjectionReason $reason): self
    {
        return new self(null, null, $reason);
    }
}
