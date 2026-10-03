<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;
use Brick\Math\BigDecimal;

final class ValuationRules
{
    /** A price dated this many days or fewer before the valuation date is current. */
    public const int PRICE_FRESHNESS_DAYS = 5;
    public const int MAX_ACTIVE_PER_ACCOUNT = 5000;
    public const int MAX_NOTE_LENGTH = 200;

    /**
     * VALUATION_ROUNDING_TOLERANCE: every priced position and the statement total may each be
     * rounded by half a display unit, so the total tolerates (priced + 1) x 0.5 x 10^-display.
     */
    public static function roundingTolerance(int $pricedPositions, int $displayPrecision): DecimalValue
    {
        return DecimalValue::fromString((string) BigDecimal::ofUnscaledValue(($pricedPositions + 1) * 5, $displayPrecision + 1));
    }

    public static function quality(?DecimalValue $price, ?\DateTimeImmutable $priceDate, \DateTimeImmutable $valuedOn): PriceQuality
    {
        if (null === $price || null === $priceDate) {
            return PriceQuality::MISSING;
        }

        return self::ageDays($priceDate, $valuedOn) > self::PRICE_FRESHNESS_DAYS ? PriceQuality::STALE : PriceQuality::CURRENT;
    }

    public static function ageDays(\DateTimeImmutable $priceDate, \DateTimeImmutable $valuedOn): int
    {
        return (int) $priceDate->setTime(0, 0)->diff($valuedOn->setTime(0, 0))->format('%r%a');
    }
}
