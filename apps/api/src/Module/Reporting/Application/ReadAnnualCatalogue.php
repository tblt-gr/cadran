<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadReportReferences;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Reads what the workspace owns, so a column can be checked against it. */
final readonly class ReadAnnualCatalogue
{
    public function __construct(
        private ReadBudgetCategoryFacts $categories,
        private ReadReportReferences $references,
    ) {
    }

    /** @throws MonthlyProjectionScopeTooLarge */
    public function __invoke(WorkspaceScope $workspace): AnnualCatalogue
    {
        try {
            $categories = ($this->categories)($workspace);
            $references = ($this->references)($workspace);
        } catch (BudgetCategoryScopeTooLarge|NetWorthScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The annual report scope exceeds its bounds.', previous: $exception);
        }
        $categoryLabels = [];
        foreach ($categories as $category) {
            $categoryLabels[$category->id] = $category->label;
        }
        $accounts = [];
        foreach ($references->accounts as $account) {
            $accounts[$account->id] = $account;
        }

        return new AnnualCatalogue($categoryLabels, $references->groups, $accounts);
    }
}
