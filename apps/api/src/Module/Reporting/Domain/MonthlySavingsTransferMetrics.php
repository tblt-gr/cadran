<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

final readonly class MonthlySavingsTransferMetrics
{
    public function __construct(
        public MonthlyMetric $savingsInflows,
        public MonthlyMetric $savingsWithdrawals,
        public MonthlyMetric $netSavingsTransfers,
        public MonthlyMetric $netSavingsRate,
    ) {
    }
}
