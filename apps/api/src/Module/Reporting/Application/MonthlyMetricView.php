<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthAmountView;
use App\Module\Reporting\Domain\MonthlyMetric;

final readonly class MonthlyMetricView
{
    public function __construct(
        public ?string $value,
        public ?string $assetCode,
        public ?string $reason,
    ) {
    }

    public static function fromMetric(MonthlyMetric $metric): self
    {
        return new self(
            $metric->value?->toString(),
            $metric->asset?->toString(),
            $metric->reason?->value,
        );
    }

    public static function fromNetWorth(?NetWorthAmountView $amount, ?string $reason): self
    {
        return new self($amount?->amount, $amount?->asset, null === $amount ? $reason : null);
    }
}
