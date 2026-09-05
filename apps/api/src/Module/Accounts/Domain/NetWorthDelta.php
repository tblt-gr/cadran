<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * How net worth moved between two business days.
 *
 * The amount and the rate are published independently, because they fail for
 * different reasons: a delta of +50 000 against a base of exactly zero is a
 * true movement with no meaningful percentage, and a base that is negative
 * makes a percentage read backwards.
 */
final readonly class NetWorthDelta
{
    public function __construct(
        public \DateTimeImmutable $comparedOn,
        public ?DecimalValue $previousTotal,
        public ?DecimalValue $amount,
        public ?NetWorthReason $amountReason,
        public ?DecimalValue $rate,
        public ?DecimalValue $ratePercent,
        public ?NetWorthReason $rateReason,
        public ?AssetCode $asset,
        public ?AssetCode $previousAsset,
    ) {
        if (null !== $amountReason && null !== $amount) {
            throw new \InvalidArgumentException('A non-calculable delta cannot carry a figure.');
        }

        if (null !== $rateReason && (null !== $rate || null !== $ratePercent)) {
            throw new \InvalidArgumentException('A non-calculable change rate cannot carry a figure.');
        }

        if (null === $rateReason && (null === $rate) !== (null === $ratePercent)) {
            throw new \InvalidArgumentException('A calculable change rate carries both a ratio and a percent.');
        }
    }

    public static function between(NetWorth $current, NetWorth $previous): self
    {
        $blocking = self::blockingReason($current, $previous);
        if (null !== $blocking) {
            return new self(
                comparedOn: $previous->asOf,
                previousTotal: $previous->total,
                amount: null,
                amountReason: $blocking,
                rate: null,
                ratePercent: null,
                rateReason: $blocking,
                // Each figure keeps the unit it was measured in. Publishing
                // the previous total under the current day's asset code is
                // how a bitcoin balance ends up displayed as euros.
                asset: $current->asset,
                previousAsset: $previous->asset,
            );
        }

        // Both totals exist and share one asset: the guard above is what makes
        // these reads safe.
        $base = $previous->total ?? throw new \LogicException('A comparable base is a figure.');
        $amount = ExactDecimal::subtract($current->total ?? throw new \LogicException('A comparable figure is a figure.'), $base);
        $rateReason = self::rateReason($base);
        $rate = null === $rateReason ? ExactDecimal::divide($amount, $base) : null;

        return new self(
            comparedOn: $previous->asOf,
            previousTotal: $base,
            amount: $amount,
            amountReason: null,
            rate: $rate,
            ratePercent: null === $rate ? null : ExactDecimal::timesHundred($rate),
            rateReason: $rateReason,
            asset: $current->asset,
            previousAsset: $previous->asset,
        );
    }

    private static function blockingReason(NetWorth $current, NetWorth $previous): ?NetWorthReason
    {
        if (null !== $current->reason) {
            return $current->reason;
        }

        if (null !== $previous->reason) {
            return $previous->reason;
        }

        return null !== $current->asset && null !== $previous->asset && !$current->asset->equals($previous->asset)
            ? NetWorthReason::MIXED_ASSETS
            : null;
    }

    private static function rateReason(DecimalValue $base): ?NetWorthReason
    {
        if (ExactDecimal::isZero($base)) {
            return NetWorthReason::ZERO_BASE;
        }

        return $base->isNegative() ? NetWorthReason::NEGATIVE_BASE : null;
    }
}
