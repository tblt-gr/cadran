<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthAmountView;
use App\Module\Reporting\Domain\MonthlyMetric;

final readonly class MonthlyMetricView
{
    /**
     * @param list<string> $sourceTransactionIds
     * @param list<string> $sourceAccountIds
     * @param list<string> $sourceTransferIds
     */
    public function __construct(
        public ?string $value,
        public ?string $assetCode,
        public ?string $reason,
        public array $sourceTransactionIds = [],
        public array $sourceAccountIds = [],
        public int $pendingCount = 0,
        public array $sourceTransferIds = [],
    ) {
    }

    public static function fromMetric(MonthlyMetric $metric): self
    {
        return new self(
            $metric->value?->toString(),
            $metric->asset?->toString(),
            $metric->reason?->value,
            $metric->sourceTransactionIds,
            [],
            $metric->pendingCount,
            $metric->sourceTransferIds,
        );
    }

    /** @param list<string> $sourceAccountIds */
    public static function fromNetWorth(?NetWorthAmountView $amount, ?string $reason, array $sourceAccountIds = []): self
    {
        return new self($amount?->amount, $amount?->asset, null === $amount ? $reason : null, [], $sourceAccountIds);
    }
}
