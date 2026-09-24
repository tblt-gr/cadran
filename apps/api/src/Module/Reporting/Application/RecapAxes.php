<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Categories\Domain\AnalyticAxis;

/** The analytic axes a monthly recap publishes, in one stable order. */
final class RecapAxes
{
    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn (AnalyticAxis $axis): string => $axis->value, AnalyticAxis::cases());
    }
}
