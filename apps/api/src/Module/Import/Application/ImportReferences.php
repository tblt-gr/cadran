<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

/**
 * What the planner needs to know about the workspace of the resolved owner:
 * accounts, categories, instruments, closed months and what an earlier import
 * left behind. Every answer is scoped to that workspace; nothing is written.
 */
interface ImportReferences
{
    /** The active account carrying exactly this label, or null when none or several do. */
    public function account(string $label): ?ImportAccount;

    /** The active category at this path from the root (`Parent/Child`), or null. */
    public function category(string $path): ?ImportCategory;

    /** The identifier of the active instrument with this name, or null. */
    public function instrument(string $name): ?string;

    /**
     * @param list<string> $months `YYYY-MM` keys
     *
     * @return list<string> the ones among them whose period is closed
     */
    public function closedMonths(array $months): array;

    /** Whether the account holds any live transaction booked before the given day. */
    public function hasTransactionBefore(string $accountId, \DateTimeImmutable $day): bool;

    /** The calendar day of the workspace: nothing may be booked after it. */
    public function today(): \DateTimeImmutable;

    /**
     * @return list<int> the years of the workbooks already imported into the workspace
     */
    public function importedYears(): array;
}
