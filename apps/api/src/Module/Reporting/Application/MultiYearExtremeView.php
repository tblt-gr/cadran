<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MultiYearExtremeView
{
    public function __construct(public string $value, public int $year)
    {
    }
}
