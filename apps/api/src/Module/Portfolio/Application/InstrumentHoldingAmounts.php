<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** Response normalization only: no rounding and no storage-width limit on calculated totals. */
final class InstrumentHoldingAmounts
{
    public static function canonical(string $literal): string
    {
        [$integer, $fraction] = array_pad(explode('.', $literal, 2), 2, '');

        return $integer.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
