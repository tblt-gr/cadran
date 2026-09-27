<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualNetWorthPointView
{
    public function __construct(public string $month, public AnnualCellView $value)
    {
    }
}
