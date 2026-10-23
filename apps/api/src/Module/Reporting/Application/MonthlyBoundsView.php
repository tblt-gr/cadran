<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyBoundsView
{
    /** @param string|null $firstDataMonth `YYYY-MM`, null for a workspace without eligible data */
    public function __construct(public ?string $firstDataMonth, public string $currentMonth)
    {
    }
}
