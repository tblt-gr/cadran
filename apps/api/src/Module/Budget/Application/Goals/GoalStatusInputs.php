<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Application\AnnualMonth;

/**
 * The workspace facts every goal status of one month reads, loaded once so that listing goals
 * does not reload a year of reporting months, the reference income, the category tree or the
 * monthly transfer pairs per goal.
 */
final readonly class GoalStatusInputs
{
    /**
     * @param array<string, AnnualMonth>                                                                    $months           keyed by YYYY-MM
     * @param list<BudgetCategoryFact>|null                                                                 $categoryFacts    null when no category fact reader is wired
     * @param array<string, array<string, array{value: ?DecimalValue, reason: ?string, asset: ?AssetCode}>> $accountTransfers by YYYY-MM, then account
     */
    public function __construct(
        public array $months,
        public ?ReferenceIncomeView $referenceIncome,
        public ?array $categoryFacts,
        public array $accountTransfers = [],
    ) {
    }
}
