<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Transactions\Application\TransactionSummaryView;

final readonly class MonthlyKpiExplanationView
{
    /**
     * @param list<string>                 $sourceTransactionIds
     * @param list<TransactionSummaryView> $sourceTransactions
     * @param list<string>                 $sourceTransferIds
     * @param list<string>                 $sourceAccountIds
     */
    public function __construct(
        public string $kpi,
        public ?string $value,
        public ?string $assetCode,
        public ?string $reason,
        public ?string $reasonExplanation,
        public string $formula,
        public string $scope,
        public string $periodStart,
        public string $periodEnd,
        public array $sourceTransactionIds,
        public array $sourceTransactions,
        public array $sourceTransferIds,
        public array $sourceAccountIds,
        public string $freshness,
        public string $quality,
        public int $pendingCount,
        public MetricPolicyReference $metricPolicy,
    ) {
    }
}
