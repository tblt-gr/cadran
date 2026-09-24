<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Transactions\Application\TransactionSummaryView;

/** Budget expenses seen through one analytic axis. */
final readonly class MonthlyRecapAxisView
{
    /** @param list<TransactionSummaryView> $sourceTransactions */
    public function __construct(
        public string $axis,
        public MonthlyMetricView $metric,
        public array $sourceTransactions,
    ) {
    }
}
