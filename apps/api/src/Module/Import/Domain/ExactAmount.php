<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\PrecisionExceeded;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/** Fits an exact decimal to an asset precision without ever rounding it. */
final class ExactAmount
{
    /**
     * `12.500` fits two decimals (it is 12.50); `12.345` does not. The result
     * always carries exactly `$precision` decimals.
     *
     * @throws PrecisionExceeded
     */
    public static function fit(DecimalValue $value, int $precision): DecimalValue
    {
        try {
            $fitted = BigDecimal::of($value->toString())->toScale(max(0, $precision), RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw new PrecisionExceeded('The amount carries more decimals than the asset precision.');
        }

        return DecimalValue::fromString((string) $fitted);
    }
}
