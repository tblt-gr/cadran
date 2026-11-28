<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Reporting\Application\Facade\ReportFigureKey;
use App\Module\Reporting\Application\LoadAnnualMonths;
use App\Module\Reporting\Application\MonthFigures;

final class ForbiddenReportingImport
{
    public function __construct(public ReportFigureKey $key, public MonthFigures $figures, public LoadAnnualMonths $months)
    {
    }
}
