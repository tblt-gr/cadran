<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/** A euro fund is worth 1 per unit by definition: any other price would fake a gain. */
final class EuroFundPrice
{
    public const string UNIT = '1';

    public static function assert(?DecimalValue $price): void
    {
        if (null !== $price && 0 !== $price->compareTo(DecimalValue::fromString(self::UNIT))) {
            throw new InvalidPortfolioOperation('A euro fund is always priced at 1.', 'euro_fund_price_fixed');
        }
    }
}
