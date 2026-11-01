<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountOverviewCompositionChartReason;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reference\Application\AssetCatalog;

/**
 * Turns the figures of one day into the split of the whole eligible net worth
 * between accounts. It only reads {@see OverviewFigures}, the very figures the
 * overview headings and rows use, so a slice share is the share of its row.
 */
final readonly class ComposeAccountOverviewComposition
{
    public const int MAX_ACCOUNTS = ReadAccountGroupComposition::MAX_ACCOUNTS;

    public function __construct(private AssetCatalog $assets)
    {
    }

    /**
     * @throws AccountOverviewCompositionTooLarge beyond {@see MAX_ACCOUNTS} eligible accounts
     */
    public function __invoke(OverviewFigures $figures): AccountOverviewCompositionView
    {
        $eligible = array_values(array_filter(
            $figures->contributions,
            static fn (NetWorthContribution $contribution): bool => $contribution->eligible,
        ));
        if (count($eligible) > self::MAX_ACCOUNTS) {
            throw new AccountOverviewCompositionTooLarge('This workspace holds more eligible accounts than one composition reads.');
        }

        $accounts = [];
        foreach ($figures->accounts as $account) {
            $accounts[$account->id] = $account;
        }
        $colors = AccountGroupColors::of($figures->groups);
        $groups = [];
        foreach ($figures->groups as $group) {
            $groups[$group->id] = $group;
        }

        $netWorth = $figures->netWorth;
        $reference = null === $netWorth->asset ? null : $this->assets->findByCode($netWorth->asset);

        $entries = [];
        foreach ($eligible as $contribution) {
            $account = $accounts[$contribution->accountId] ?? null;
            if (!$account instanceof Account) {
                continue;
            }
            $group = null === $account->primaryGroupId ? null : $groups[$account->primaryGroupId] ?? null;
            $asset = $contribution->amount?->asset;
            $entries[] = [
                $contribution->signedValue(),
                new AccountOverviewCompositionAccountView(
                    $account->id,
                    $account->label,
                    $account->institution,
                    $account->primaryGroupId,
                    $group instanceof AccountGroup ? $colors->effective($group) : null,
                    NetWorthAmountView::of($contribution->signedValue(), $asset, null === $asset ? null : $this->assets->findByCode($asset)),
                    ShareView::fromShare($figures->shares->account($account->id)),
                ),
            ];
        }
        usort($entries, static fn (array $left, array $right): int => self::compare($left, $right));

        return new AccountOverviewCompositionView(
            asOf: $figures->day->format('Y-m-d'),
            total: NetWorthAmountView::of($netWorth->total, $netWorth->asset, $reference),
            totalReason: $netWorth->reason?->value,
            chartReason: AccountOverviewCompositionChartReason::of(array_map(
                static fn (NetWorthContribution $contribution) => $contribution->share(),
                $eligible,
            ))?->value,
            accounts: array_column($entries, 1),
        );
    }

    /**
     * Value descending on exact decimals, accounts without a value last, then
     * label and id so the order never depends on the storage order.
     *
     * @param array{0: ?DecimalValue, 1: AccountOverviewCompositionAccountView} $left
     * @param array{0: ?DecimalValue, 1: AccountOverviewCompositionAccountView} $right
     */
    private static function compare(array $left, array $right): int
    {
        [$leftValue, $leftView] = $left;
        [$rightValue, $rightView] = $right;

        $byValue = match (true) {
            null === $leftValue && null === $rightValue => 0,
            null === $leftValue => 1,
            null === $rightValue => -1,
            default => $rightValue->compareTo($leftValue),
        };

        return $byValue
            ?: strcmp($leftView->label, $rightView->label)
            ?: strcmp($leftView->id, $rightView->id);
    }
}
