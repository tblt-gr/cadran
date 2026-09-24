<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Transactions\Application\TransactionSummaryView;

/**
 * The month's movement totals, already computed. Every figure travels with the
 * reason it is absent and the sources behind it, so the interface never
 * derives money or a rate of its own.
 */
final readonly class MonthlyRecapTotalsView
{
    /**
     * @param list<MonthlyRecapAxisView>     $expensesByAxis
     * @param list<MonthlyRecapCategoryView> $categories
     */
    public function __construct(
        public MonthlyMetricView $cashIncome,
        public MonthlyMetricView $budgetExpenses,
        public MonthlyMetricView $savingsInflows,
        public MonthlyMetricView $savingsWithdrawals,
        public MonthlyMetricView $netSavingsTransfers,
        public MonthlyMetricView $netSavingsRate,
        public array $expensesByAxis,
        public array $categories,
    ) {
    }

    /** @param array<string, TransactionSummaryView> $sourceTransactionsById */
    public static function of(MonthlyProjectionView $projection, array $sourceTransactionsById): self
    {
        $axes = [];
        foreach ($projection->expensesByAxis as $axis => $metric) {
            $axes[] = new MonthlyRecapAxisView(
                $axis,
                $metric,
                self::sources($metric->sourceTransactionIds, $sourceTransactionsById),
            );
        }
        $categories = array_map(
            static fn (MonthlyRecapCategoryView $category): MonthlyRecapCategoryView => new MonthlyRecapCategoryView(
                $category->id,
                $category->label,
                $category->type,
                $category->metric,
                self::sources($category->metric->sourceTransactionIds, $sourceTransactionsById),
            ),
            $projection->categoryMetrics,
        );

        return new self(
            $projection->cashIncome,
            $projection->budgetExpenses,
            $projection->savingsInflows,
            $projection->savingsWithdrawals,
            $projection->netSavingsTransfers,
            $projection->netSavingsRate,
            $axes,
            $categories,
        );
    }

    /**
     * @param list<string>                          $sourceTransactionIds
     * @param array<string, TransactionSummaryView> $sourceTransactionsById
     *
     * @return list<TransactionSummaryView>
     */
    private static function sources(array $sourceTransactionIds, array $sourceTransactionsById): array
    {
        $sources = [];
        foreach ($sourceTransactionIds as $id) {
            if (isset($sourceTransactionsById[$id])) {
                $sources[] = $sourceTransactionsById[$id];
            }
        }

        return $sources;
    }
}
