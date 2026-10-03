<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/**
 * The fees and taxes portfolio accounts carried over a calendar month: an informative figure the monthly
 * recap shows outside every budget total. Read live, since the operations of a closed month cannot change.
 */
final readonly class ReadMonthlyPortfolioCosts
{
    private const int MAX_ACCOUNTS = 500;

    public function __construct(private AccountRepository $accounts, private PortfolioOperationRepository $operations)
    {
    }

    /**
     * @return MonthlyPortfolioCosts|null null only when the workspace holds no portfolio-mode account. When the
     *                                    accounts of the month mix assets, each item keeps its own asset and the totals
     *                                    and the block asset are null (multi-currency is not supported)
     */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): ?MonthlyPortfolioCosts
    {
        /** @var array<string, Account> $portfolios */
        $portfolios = [];
        foreach ($this->accounts->list($workspace, true, true, self::MAX_ACCOUNTS, 0) as $account) {
            if (AccountValuationMode::PORTFOLIO === $account->valuationMode) {
                $portfolios[$account->id] = $account;
            }
        }
        if ([] === $portfolios) {
            return null;
        }

        $items = [];
        foreach ($this->operations->costsBetween($workspace, $month->firstDay(), $month->lastDay()) as $costs) {
            $account = $portfolios[$costs->accountId] ?? null;
            if (null !== $account) {
                $items[] = new MonthlyPortfolioCostItem($account->id, $account->label, $account->assetCode->toString(), $costs->fees, $costs->taxes);
            }
        }
        usort($items, static fn (MonthlyPortfolioCostItem $a, MonthlyPortfolioCostItem $b): int => [$a->label, $a->accountId] <=> [$b->label, $b->accountId]);

        $assets = array_values(array_unique([] === $items
            ? array_map(static fn (Account $account): string => $account->assetCode->toString(), $portfolios)
            : array_map(static fn (MonthlyPortfolioCostItem $item): string => $item->assetCode, $items)));
        if (1 !== count($assets)) {
            return new MonthlyPortfolioCosts($items, null, null, null);
        }
        $fees = DecimalValue::zero();
        $taxes = DecimalValue::zero();
        foreach ($items as $item) {
            $fees = ExactDecimal::add($fees, $item->fees);
            $taxes = ExactDecimal::add($taxes, $item->taxes);
        }

        return new MonthlyPortfolioCosts($items, $fees, $taxes, $assets[0]);
    }
}
