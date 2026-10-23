<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerView
{
    /**
     * @param list<MonthlyLedgerCategoryRowView> $incomeCategories
     * @param list<MonthlyLedgerCategoryRowView> $expenseCategories
     * @param list<MonthlyLedgerAccountRowView>  $accounts
     */
    public function __construct(
        public string $month,
        public string $periodStart,
        public string $periodEnd,
        public ?string $axis,
        public string $state,
        public string $quality,
        public string $timezone,
        public ?string $firstDataMonth,
        public int $pendingCount,
        public bool $closed,
        public bool $actionsAllowed,
        public ?string $actionReason,
        public array $incomeCategories,
        public array $expenseCategories,
        public array $accounts,
    ) {
    }
}
