<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Reporting\Application\AnnualColumnNotFound;
use App\Module\Reporting\Application\InvalidAnnualReportQuery;
use App\Module\Reporting\Application\InvalidMultiYearReportQuery;
use App\Module\Reporting\Application\InvalidReportYear;
use App\Module\Reporting\Application\InvalidYearRange;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\YearRangeTooLarge;
use Symfony\Component\HttpFoundation\Response;

/** Which problem document each exception of the annual and multi-year reports becomes. */
final class ReportingProblems
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function annual(): array
    {
        return [
            InvalidReportYear::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_report_year', AnnualReportController::TYPE_INVALID_YEAR),
            InvalidAnnualReportQuery::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_annual_report_query'),
            AnnualColumnNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.annual_column_not_found'),
            ...self::projection(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function multiYear(): array
    {
        return [
            InvalidYearRange::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_year_range', MultiYearReportController::TYPE_INVALID_YEAR_RANGE),
            YearRangeTooLarge::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.range_too_large', MultiYearReportController::TYPE_RANGE_TOO_LARGE),
            InvalidMultiYearReportQuery::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_multi_year_report_query'),
            ...self::projection(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function projection(): array
    {
        return [
            MonthlyProjectionScopeTooLarge::class => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.monthly_projection_scope_too_large',
                MonthlyProjectionController::TYPE_SCOPE_TOO_LARGE,
            ),
            WorkspaceAccessDenied::class => new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden'),
        ];
    }
}
