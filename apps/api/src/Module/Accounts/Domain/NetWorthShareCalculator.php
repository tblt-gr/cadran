<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Exclusive account and group weights against eligible net worth.
 *
 * Only an account included in net worth, and only its primary group lineage,
 * enters the exclusive denominator. Tags are ignored here so a second label
 * cannot inflate a percentage.
 */
final class NetWorthShareCalculator
{
    /**
     * @param list<AccountShareInput> $accounts
     * @param list<GroupLineage>      $lineages
     */
    public static function compute(array $accounts, array $lineages): NetWorthShares
    {
        $included = array_values(array_filter(
            $accounts,
            static fn (AccountShareInput $account): bool => $account->includeInNetWorth,
        ));

        $reason = self::nonCalculableReason($included);
        $accountShares = [];
        $groupShares = [];

        foreach ($accounts as $account) {
            $accountShares[$account->accountId] = $account->includeInNetWorth
                ? (null === $reason ? self::share($account->value, $account->netWorthSign, self::eligibleNetWorth($included)) : NetWorthShare::none($reason))
                : NetWorthShare::none(null);
        }

        $rollup = null === $reason ? ExclusiveGroupRollup::totals($included, $lineages) : [];
        foreach ($lineages as $lineage) {
            $groupShares[$lineage->groupId] = null === $reason
                ? self::ratio($rollup[$lineage->groupId], self::eligibleNetWorth($included))
                : NetWorthShare::none($reason);
        }

        return new NetWorthShares($accountShares, $groupShares);
    }

    /**
     * @param list<AccountShareInput> $included
     */
    private static function nonCalculableReason(array $included): ?NetWorthShareReason
    {
        foreach ($included as $account) {
            if (null === $account->value) {
                return NetWorthShareReason::MISSING_VALUATION;
            }
        }

        // A denominator that adds euros to bitcoin would publish a percentage
        // of nothing. Conversion is out of scope, so the weight is refused.
        if (count(self::assetCodes($included)) > 1) {
            return NetWorthShareReason::MIXED_ASSETS;
        }

        if ([] === $included) {
            return NetWorthShareReason::ZERO_ELIGIBLE_NET_WORTH;
        }

        $eligible = self::eligibleNetWorth($included);
        if (ExactDecimal::isZero($eligible)) {
            return NetWorthShareReason::ZERO_ELIGIBLE_NET_WORTH;
        }

        if ($eligible->isNegative()) {
            return NetWorthShareReason::NEGATIVE_ELIGIBLE_NET_WORTH;
        }

        return null;
    }

    /**
     * @param list<AccountShareInput> $included
     *
     * @return list<string>
     */
    private static function assetCodes(array $included): array
    {
        $codes = [];
        foreach ($included as $account) {
            if (null !== $account->asset) {
                $codes[$account->asset->toString()] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param list<AccountShareInput> $included
     */
    private static function eligibleNetWorth(array $included): DecimalValue
    {
        $total = DecimalValue::fromString('0');
        foreach ($included as $account) {
            if (null === $account->value) {
                continue;
            }

            $total = ExactDecimal::add($total, ExactDecimal::signed($account->value, $account->netWorthSign));
        }

        return $total;
    }

    private static function share(?DecimalValue $value, int $sign, DecimalValue $eligible): NetWorthShare
    {
        return self::ratio(ExactDecimal::signed($value ?? DecimalValue::fromString('0'), $sign), $eligible);
    }

    private static function ratio(DecimalValue $numerator, DecimalValue $eligible): NetWorthShare
    {
        $ratio = ExactDecimal::divide($numerator, $eligible);

        return NetWorthShare::of($ratio, ExactDecimal::timesHundred($ratio));
    }
}
