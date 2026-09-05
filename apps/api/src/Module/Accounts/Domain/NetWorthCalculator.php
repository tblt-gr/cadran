<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Sums the signed values of the accounts included in net worth on one day.
 *
 * The sum is refused rather than approximated: one eligible account without a
 * valuation, or two eligible accounts in different assets, and the total is
 * null with the reason. Conversion between assets is out of scope, so adding
 * across units here would be an invented figure.
 */
final class NetWorthCalculator
{
    /**
     * @param list<NetWorthContribution> $contributions
     */
    public static function compute(array $contributions, \DateTimeImmutable $asOf): NetWorth
    {
        $eligible = array_values(array_filter(
            $contributions,
            static fn (NetWorthContribution $contribution): bool => $contribution->eligible,
        ));

        $missing = self::countQuality($eligible, ValuationQuality::MISSING);
        $stale = self::countQuality($eligible, ValuationQuality::STALE);
        $reason = self::reason($eligible, $missing);

        return new NetWorth(
            asOf: $asOf,
            total: null === $reason ? self::total($eligible) : null,
            asset: null === $reason ? self::asset($eligible) : null,
            reason: $reason,
            quality: match (true) {
                null !== $reason => ValuationQuality::MISSING,
                $stale > 0 => ValuationQuality::STALE,
                default => ValuationQuality::CURRENT,
            },
            stalestAgeDays: self::stalestAgeDays($eligible),
            eligibleAccountCount: count($eligible),
            valuedAccountCount: count($eligible) - $missing,
            missingValuationCount: $missing,
            staleValuationCount: $stale,
            contributions: $contributions,
        );
    }

    /**
     * @param list<NetWorthContribution> $eligible
     */
    private static function reason(array $eligible, int $missing): ?NetWorthReason
    {
        if ([] === $eligible) {
            return NetWorthReason::NO_ELIGIBLE_ACCOUNT;
        }

        if ($missing > 0) {
            return NetWorthReason::MISSING_VALUATION;
        }

        return count(self::assetCodes($eligible)) > 1 ? NetWorthReason::MIXED_ASSETS : null;
    }

    /**
     * @param list<NetWorthContribution> $eligible
     *
     * @return list<string>
     */
    private static function assetCodes(array $eligible): array
    {
        $codes = [];
        foreach ($eligible as $contribution) {
            if (null !== $contribution->amount) {
                $codes[$contribution->amount->asset->toString()] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param list<NetWorthContribution> $eligible
     */
    private static function total(array $eligible): DecimalValue
    {
        $total = DecimalValue::fromString('0');
        foreach ($eligible as $contribution) {
            $signed = $contribution->signedValue();
            if (null !== $signed) {
                $total = ExactDecimal::add($total, $signed);
            }
        }

        return $total;
    }

    /**
     * @param list<NetWorthContribution> $eligible
     */
    private static function asset(array $eligible): ?AssetCode
    {
        $codes = self::assetCodes($eligible);

        return [] === $codes ? null : AssetCode::fromString($codes[0]);
    }

    /**
     * @param list<NetWorthContribution> $eligible
     */
    private static function stalestAgeDays(array $eligible): ?int
    {
        $ages = [];
        foreach ($eligible as $contribution) {
            if (null !== $contribution->ageDays) {
                $ages[] = $contribution->ageDays;
            }
        }

        return [] === $ages ? null : max($ages);
    }

    /**
     * @param list<NetWorthContribution> $eligible
     */
    private static function countQuality(array $eligible, ValuationQuality $quality): int
    {
        return count(array_filter(
            $eligible,
            static fn (NetWorthContribution $contribution): bool => $quality === $contribution->quality,
        ));
    }
}
