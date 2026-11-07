<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Weights of the holdings against the securities value of the items. Strings and Brick Math only:
 * the sum of many large positions may exceed the storage width of a DecimalValue, and a share
 * must stay exact rather than fail.
 */
final class HoldingsShares
{
    /** Presentation scale of a percentage, as the net-worth shares. */
    public const int PERCENT_DISPLAY_SCALE = 2;

    /** @return array{ratio: ?string, percentDisplay: ?string, reason: ?string} */
    public static function of(?string $value, ?string $total, bool $mixedAssets): array
    {
        if ($mixedAssets) {
            return self::none('MIXED_ASSETS');
        }
        if (null === $total || null === $value) {
            return self::none('MISSING_VALUE');
        }
        $denominator = BigDecimal::of($total);
        if ($denominator->isZero()) {
            return self::none('ZERO_TOTAL');
        }
        if ($denominator->isNegative()) {
            return self::none('NEGATIVE_TOTAL');
        }
        $ratio = ExactDecimal::divideForResponse($value, $total, DecimalValue::MAX_SCALE);

        return ['ratio' => $ratio,
            'percentDisplay' => (string) BigDecimal::of($ratio)->multipliedBy(100)->toScale(self::PERCENT_DISPLAY_SCALE, RoundingMode::HalfUp),
            'reason' => null];
    }

    /** @return array{ratio: null, percentDisplay: null, reason: string} */
    private static function none(string $reason): array
    {
        return ['ratio' => null, 'percentDisplay' => null, 'reason' => $reason];
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $groups
     *
     * @return list<array{key: string, label: string, value: string, share: ?string}>
     */
    public static function slices(array $groups, ?string $total): array
    {
        $positive = null !== $total && BigDecimal::of($total)->isPositive();

        return array_map(static fn (array $group): array => [...$group,
            'share' => $positive ? ExactDecimal::divideForResponse($group['value'], $total, DecimalValue::MAX_SCALE) : null], $groups);
    }
}
