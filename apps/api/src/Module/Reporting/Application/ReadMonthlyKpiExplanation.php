<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Application\ReadTransactionSummaries;

final readonly class ReadMonthlyKpiExplanation
{
    public function __construct(
        private ReadMonthlyProjection $projection,
        private CallerWorkspace $caller,
        private ReadTransactionSummaries $transactionSummaries,
    ) {
    }

    public function __invoke(string $requestedMonth, string $requestedKpi): MonthlyKpiExplanationView
    {
        $kpi = MonthlyKpi::tryFrom($requestedKpi);
        if (null === $kpi) {
            throw new InvalidMonthlyKpiExplanationQuery('The requested monthly KPI is not supported.');
        }

        $projection = ($this->projection)($requestedMonth);
        $metric = $kpi->metric($projection);

        return new MonthlyKpiExplanationView(
            kpi: $kpi->value,
            value: $metric->value,
            assetCode: $metric->assetCode,
            reason: $metric->reason,
            reasonExplanation: MonthlyKpiReasonExplanation::for($metric->reason),
            formula: $kpi->formula(),
            scope: $kpi->scope(),
            periodStart: $projection->periodStart,
            periodEnd: $projection->periodEnd,
            sourceTransactionIds: $metric->sourceTransactionIds,
            sourceTransactions: ($this->transactionSummaries)($this->caller->resolve(), $metric->sourceTransactionIds),
            sourceAccountIds: $metric->sourceAccountIds,
            freshness: $metric->pendingCount > 0 ? 'PENDING' : 'CURRENT',
            quality: $projection->quality,
            pendingCount: $metric->pendingCount,
        );
    }
}
