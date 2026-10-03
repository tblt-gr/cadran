<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use Brick\Math\BigDecimal;

/**
 * Exact valuation figures. A market value is quantity x price with no rounding; a missing price is
 * null and makes every total it belongs to null, never zero.
 *
 * @throws \App\Module\Foundation\Domain\PrecisionExceeded when a figure cannot be stored on 24 decimals and 26 integer digits
 */
final class ValuationCalculator
{
    /** @param list<HeldPosition> $positions */
    public static function total(
        DecimalValue $derivedCash,
        ?DecimalValue $observedCash,
        DecimalValue $totalValue,
        array $positions,
    ): ValuationFigures {
        $cash = $observedCash ?? $derivedCash;
        $lines = array_map(
            static fn (HeldPosition $held): ValuationLine => new ValuationLine($held->instrumentId, $held->quantity, null, null, null, $held->averageCostBasis, PriceQuality::NOT_DETAILED),
            $positions,
        );

        return new ValuationFigures($cash, null !== $observedCash, ExactDecimal::subtract($totalValue, $cash), $totalValue, ValuationCompleteness::COMPLETE, $lines);
    }

    /**
     * @param list<HeldPosition>        $positions
     * @param array<string, PriceEntry> $prices    instrument identifier => entry, one per held position
     */
    public static function positions(
        \DateTimeImmutable $valuedOn,
        int $displayPrecision,
        DecimalValue $derivedCash,
        ?DecimalValue $observedCash,
        array $positions,
        array $prices,
    ): ValuationFigures {
        $cash = $observedCash ?? $derivedCash;
        $lines = [];
        $securities = DecimalValue::zero();
        $complete = true;
        foreach ($positions as $held) {
            $entry = $prices[$held->instrumentId] ?? new PriceEntry(null, null);
            $market = null === $entry->price ? null : self::product($held->quantity, $entry->price, $displayPrecision);
            if (null === $market) {
                $complete = false;
            } else {
                $securities = ExactDecimal::add($securities, $market);
            }
            $lines[] = new ValuationLine(
                $held->instrumentId, $held->quantity, $entry->price, $entry->priceDate, $market, $held->averageCostBasis,
                ValuationRules::quality($entry->price, $entry->priceDate, $valuedOn),
            );
        }

        return new ValuationFigures(
            $cash,
            null !== $observedCash,
            $complete ? $securities : null,
            $complete ? ExactDecimal::add($cash, $securities) : null,
            $complete ? ValuationCompleteness::COMPLETE : ValuationCompleteness::PARTIAL,
            $lines,
        );
    }

    /** Exact product, its trailing zeros trimmed down to the display precision so 156.900 reads 156.90. */
    private static function product(DecimalValue $quantity, DecimalValue $price, int $displayPrecision): DecimalValue
    {
        $product = BigDecimal::of($quantity->toString())->multipliedBy(BigDecimal::of($price->toString()));
        $scale = $product->getScale();
        while ($scale > $displayPrecision && $product->getUnscaledValue()->remainder(10)->isZero()) {
            $product = $product->toScale(max(0, $scale - 1));
            --$scale;
        }

        return DecimalValue::fromString((string) $product);
    }
}
