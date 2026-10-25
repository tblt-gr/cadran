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
        /** Spending on accounts outside the treasury perimeter, told apart and never part of `metric`. */
        public ?string $benefitSpending = null,
        public ?string $benefitAssetCode = null,
    ) {
    }

    /**
     * The total covers the category and all its descendants, so a parent never reads zero while its
     * subcategories carry the month's movements.
     *
     * @param list<MonthlyLedgerEntry> $entries
     * @param list<string>             $descendantIds
     * @param list<MonthlyLedgerEntry> $benefitEntries booked movements of the accounts the metric policy excludes
     */
    public static function of(BudgetCategoryFact $category, array $entries, array $descendantIds = [], array $benefitEntries = []): self
    {
        $sources = MonthlyLedgerCalculator::categorySources($entries, $category->type, [$category->id, ...$descendantIds], null);
        $total = MonthlyLedgerCalculator::total($sources, 'EXPENSE' === $category->type);
        $sourceTransactionIds = array_values(array_unique(array_map(
            static fn ($source): string => $source->transactionId,
            $sources,
        )));
        sort($sourceTransactionIds, SORT_STRING);
        $benefitSpending = null;
        $benefitAssetCode = null;
        if ('EXPENSE' === $category->type && [] !== $benefitEntries) {
            // Same sign convention as the benefit spending indicator: expenses and fees count,
            // refunds reduce, and the result is null when nothing moved or the assets are mixed.
            $benefit = MonthlyLedgerCalculator::total(
                MonthlyLedgerCalculator::categorySources($benefitEntries, $category->type, [$category->id, ...$descendantIds], null),
                true,
            );
            $benefitSpending = $benefit->value?->toString();
            $benefitAssetCode = null === $benefitSpending ? null : $benefit->asset?->toString();
        }

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
            [],
            $benefitSpending,
            $benefitAssetCode,
        );
    }
}
