<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\ListPortfolioAccounts;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Every holding of the retained valuations of the portfolio accounts, aggregated by instrument. */
final readonly class ReadHoldings
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ListPortfolioAccounts $accounts,
        private WorkspaceCalendar $calendar,
        private PortfolioOverviewQuery $query,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(?string $sort): array
    {
        $sort ??= 'VALUE_DESC';
        if (!in_array($sort, HoldingsAggregator::SORTS, true)) {
            throw new InvalidHoldingsQuery('The holdings order is invalid.');
        }
        $workspace = $this->caller->resolveContext()->workspace;
        $accounts = ($this->accounts)($workspace);
        $shown = array_slice($accounts, 0, ListPortfolios::MAX_ACCOUNTS);
        $inputs = [];
        foreach ($shown as $account) {
            try {
                [, , $view] = ($this->query)($account->id, null);
            } catch (CashRangeTooDense) {
                // One account beyond its read bound must not take the whole page down: it is listed unsplit.
                $view = null;
            }
            $inputs[] = new HoldingsAccount($account->id, $account->label, $account->assetCode, $view);
        }

        return HoldingsAggregator::aggregate($inputs, $this->calendar->today(), $sort, count($accounts) > ListPortfolios::MAX_ACCOUNTS);
    }
}
