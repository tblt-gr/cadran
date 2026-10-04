<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;

/** Canonical response form of an exact amount: trailing zeros trimmed, never below the display precision. */
final class PerformanceAmount
{
    public static function canonical(CalculatedDecimal $value, int $displayPrecision): string
    {
        $literal = $value->toString();
        [$integer, $fraction] = array_pad(explode('.', $literal, 2), 2, '');
        $fraction = str_pad(rtrim($fraction, '0'), max(0, $displayPrecision), '0');

        return '' === $fraction ? $integer : $integer.'.'.$fraction;
    }
}
