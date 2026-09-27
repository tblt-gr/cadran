<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

/** Demo amounts are integer cents end to end; only the API edge sees a decimal string. */
final class DemoCents
{
    public static function format(int $cents): string
    {
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }
}
