<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualExtremeView
{
    public function __construct(public string $value, public string $month)
    {
    }
}
