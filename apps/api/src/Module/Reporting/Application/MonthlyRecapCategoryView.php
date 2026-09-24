<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Reporting\Domain\MonthlyLedgerCalculator;
use App\Module\Reporting\Domain\MonthlyLedgerEntry;
use App\Module\Transactions\Application\TransactionSummaryView;

/** One selectable category total, computed with the monthly ledger policy. */
final readonly class MonthlyRecapCategoryView
{
    /** @param list<TransactionSummaryView> $sourceTransactions */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public MonthlyMetricView $metric,
        public array $sourceTransactions = [],
    ) {
    }

    /**
     * @param list<MonthlyLedgerEntry> $entries
     */
    public static function of(BudgetCategoryFact $category, array $entries): self
    {
        $sources = MonthlyLedgerCalculator::categorySources($entries, $category->type, $category->id, null);
        $total = MonthlyLedgerCalculator::total($sources, 'EXPENSE' === $category->type);
        $sourceTransactionIds = array_values(array_unique(array_map(
            static fn ($source): string => $source->transactionId,
            $sources,
        )));
        sort($sourceTransactionIds, SORT_STRING);

        return new self(
            $category->id,
            $category->label,
            $category->type,
            new MonthlyMetricView(
                $total->value?->toString(),
                $total->asset?->toString(),
                $total->reason,
                $sourceTransactionIds,
            ),
        );
    }
}
