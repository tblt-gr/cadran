<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\ListPortfolioAccounts;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\DueMonthWindow;

/** The month-end valuations a workspace still owes, oldest month first; nothing is stored. */
final readonly class ReadMonthEndValuationReminders
{
    /** Every listed account over the whole window, so the list is never cut. */
    public const int MAX_ITEMS = ListPortfolios::MAX_ACCOUNTS * (DueMonthWindow::MAX_MONTHS_BACK + 1);

    public function __construct(private ListPortfolioAccounts $accounts, private PortfolioDueMonths $dueMonths)
    {
    }

    /** @return array{today: string, items: list<array{accountId: string, accountLabel: string, month: string, valuationDate: string}>} */
    public function __invoke(WorkspaceScope $workspace): array
    {
        $today = $this->dueMonths->today($workspace);
        $accounts = array_slice(($this->accounts)($workspace), 0, ListPortfolios::MAX_ACCOUNTS);
        $labels = [];
        foreach ($accounts as $account) {
            $labels[$account->id] = $account->label;
        }
        $items = [];
        foreach (($this->dueMonths)($workspace, $accounts, $today) as $accountId => $months) {
            $label = $labels[$accountId] ?? '';
            foreach ($months as $month) {
                $items[] = ['accountId' => $accountId, 'accountLabel' => $label, 'month' => $month,
                    'valuationDate' => CalendarMonth::fromString($month)->lastDay()->format('Y-m-d')];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['month'], $a['accountLabel'], $a['accountId']] <=> [$b['month'], $b['accountLabel'], $b['accountId']]);

        return ['today' => $today->format('Y-m-d'), 'items' => array_slice($items, 0, self::MAX_ITEMS)];
    }
}
