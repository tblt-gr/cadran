<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Import\Domain\ReasonCode;

/**
 * Splits the term of a purchase into a quantity and a unit price. A product
 * `q*p` names its quantity as the factor without a fractional part; when both
 * or neither factor is whole only the mapping can say which side the quantity
 * is on. A plain amount is bought as that quantity at unit price 1, since the
 * workbook never records the number of shares.
 */
final class QuantityResolver
{
    public const int QUANTITY_PRECISION = 8;
    public const int PRICE_PRECISION = 4;

    /**
     * @param list<DecimalValue> $factors   the two factors of a product term, in the order written
     * @param list<bool>|null    $override  the mapping's `pea_quantity_first` flags, one per term
     * @param int                $termIndex 0-based position of the term in its cell
     *
     * @return array{DecimalValue, DecimalValue}|ReasonCode quantity and unit price, or why they cannot be told apart
     */
    public static function ofProduct(array $factors, ?array $override, int $termIndex): array|ReasonCode
    {
        [$first, $second] = $factors;
        $firstWhole = self::isWhole($first);
        $secondWhole = self::isWhole($second);

        $quantityFirst = match (true) {
            $firstWhole && !$secondWhole => true,
            !$firstWhole && $secondWhole => false,
            default => $override[$termIndex] ?? null,
        };
        if (null === $quantityFirst) {
            return ReasonCode::AMBIGUOUS_QUANTITY;
        }
        [$quantity, $price] = $quantityFirst ? [$first, $second] : [$second, $first];

        return self::fits($quantity, $price) ? [$quantity, $price] : ReasonCode::PRECISION_EXCEEDED;
    }

    /**
     * @return array{DecimalValue, DecimalValue}|ReasonCode
     */
    public static function ofAmount(DecimalValue $amount): array|ReasonCode
    {
        $price = DecimalValue::fromString('1');

        return self::fits($amount, $price) ? [$amount, $price] : ReasonCode::PRECISION_EXCEEDED;
    }

    private static function fits(DecimalValue $quantity, DecimalValue $price): bool
    {
        return self::effectiveScale($quantity) <= self::QUANTITY_PRECISION && self::effectiveScale($price) <= self::PRICE_PRECISION;
    }

    private static function isWhole(DecimalValue $value): bool
    {
        return 0 === ExactDecimal::round($value, 0, RoundingMode::DOWN)->compareTo($value);
    }

    /** Decimals that carry information: `100.00` has none, `95.1200` has two. */
    private static function effectiveScale(DecimalValue $value): int
    {
        $literal = $value->toString();
        if (!str_contains($literal, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($literal, (int) strpos($literal, '.') + 1), '0'));
    }
}
