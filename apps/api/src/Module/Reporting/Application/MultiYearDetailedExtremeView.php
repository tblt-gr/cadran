<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** One extreme month of the whole detailed period, with its `YYYY-MM` key. */
final readonly class MultiYearDetailedExtremeView
{
    public function __construct(public string $value, public string $month)
    {
    }
}
