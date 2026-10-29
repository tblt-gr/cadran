<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\Aggregation\AggregateQuality;
use App\Module\Reporting\Domain\Aggregation\AggregateReason;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Reporting\Domain\Aggregation\ReportAggregator;

/**
 * covered months  = eligible liquid assets / average monthly essential expenses
 * required amount = target months x average monthly essential expenses
 *                   (or the fixed amount, or ratio x 12 x average monthly income, when the policy sets it)
 * gap             = liquid assets - required amount.
 *
 * The average is the report aggregator's, running month excluded, so the ratio can never disagree
 * with the reports it is explained by. A zero or unknown denominator gives a null ratio with its
 * reason, never an infinite or zero coverage.
 */
final class EmergencyFundCalculator
{
    private const int DISPLAY_SCALE = 1;

    /**
     * @param list<LiquidAccount> $accounts the eligible accounts at the as-of date
     * @param list<MonthValue>    $months   essential expenses of every reference month
     */
    public static function compute(array $accounts, array $months, DecimalValue $targetMonths, int $expectedMonths, ?FixedRequirement $fixed = null): EmergencyFundCoverage
    {
        [$liquid, $liquidAsset, $liquidReason] = self::liquid($accounts);
        $aggregate = ReportAggregator::flow($months, IncompleteMonths::EXCLUDE);
        $average = $aggregate->average;
        $averageReason = match ($aggregate->averageReason) {
            null => null,
            AggregateReason::EMPTY_POPULATION => 'NO_REFERENCE_MONTH',
            default => $aggregate->averageReason->value,
        };
        $requiredAsset = $fixed->assetCode ?? $aggregate->asset?->toString();
        $comparable = null === $liquidAsset || null === $requiredAsset || $requiredAsset === $liquidAsset;
        $required = null !== $fixed
            ? $fixed->amount
            : (null === $average ? null : ExactDecimal::multiplyCanonicalForResponse($average->toString(), $targetMonths));
        $gap = null === $liquid || null === $required || !$comparable ? null : ExactDecimal::subtractForResponse($liquid, $required);

        $coverageReason = $liquidReason
            ?? ($comparable ? null : AggregateReason::MIXED_ASSETS->value)
            ?? $averageReason
            ?? match (true) {
                null === $average => 'NO_REFERENCE_MONTH',
                ExactDecimal::isZero($average) => 'ZERO_ESSENTIAL_EXPENSES',
                $average->isNegative() => 'NEGATIVE_ESSENTIAL_EXPENSES',
                default => null,
            };
        $covered = null;
        $display = null;
        if (null === $coverageReason && null !== $liquid && null !== $average) {
            $covered = ExactDecimal::divideForResponse($liquid, $average->toString());
            $display = ExactDecimal::divideForResponse($covered, '1', self::DISPLAY_SCALE);
        }
        $stale = [] !== array_filter($accounts, static fn (LiquidAccount $account): bool => $account->isStale());
        $usedMonths = count($aggregate->countedMonths);

        return new EmergencyFundCoverage(
            $accounts,
            $liquid,
            $liquidAsset,
            $liquidReason,
            $average?->toString(),
            $averageReason,
            $aggregate->asset?->toString(),
            $usedMonths,
            $expectedMonths,
            $aggregate->excludedMonths,
            $required,
            $gap,
            $covered,
            $display,
            // A fixed target needs no expense average: only what stops the gap is a reason.
            null !== $fixed
                ? ($liquidReason ?? ($comparable ? null : AggregateReason::MIXED_ASSETS->value) ?? $fixed->reason ?? ($stale ? 'STALE_VALUATION' : null))
                : ($coverageReason ?? ($stale ? 'STALE_VALUATION' : null)),
            match (true) {
                null !== $fixed => null === $gap ? 'NOT_CALCULABLE' : ($stale ? 'PARTIAL' : 'COMPLETE'),
                null === $covered => 'NOT_CALCULABLE',
                $stale || $usedMonths < $expectedMonths || AggregateQuality::COMPLETE !== $aggregate->quality => 'PARTIAL',
                default => 'COMPLETE',
            },
        );
    }

    /**
     * @param list<LiquidAccount> $accounts
     *
     * @return array{?string, ?string, ?string} value, asset code and reason
     */
    private static function liquid(array $accounts): array
    {
        if ([] === $accounts) {
            return [null, null, 'NO_ELIGIBLE_ACCOUNT'];
        }
        $assets = [];
        $sum = '0';
        foreach ($accounts as $account) {
            if (null === $account->value || null === $account->asset) {
                return [null, null, 'MISSING_VALUATION'];
            }
            $assets[$account->asset->toString()] = true;
            $sum = ExactDecimal::addForResponse($sum, $account->value->toString());
        }
        if (count($assets) > 1) {
            return [null, null, AggregateReason::MIXED_ASSETS->value];
        }

        return [$sum, array_key_first($assets), null];
    }
}
