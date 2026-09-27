<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reporting\Application\AnnualColumnNotFound;
use App\Module\Reporting\Application\BuildAnnualReport;
use App\Module\Reporting\Application\InvalidAnnualReportQuery;
use App\Module\Reporting\Application\InvalidReportYear;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnnualReportController
{
    public const string TYPE_INVALID_YEAR = '/problems/invalid-report-year';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/annual/{year}', name: 'api_v1_reports_annual', requirements: ['year' => '\d{1,9}'], methods: ['GET'])]
    public function __invoke(int $year, Request $request, BuildAnnualReport $build): Response
    {
        try {
            $report = $build($year, self::incompleteMonths($request));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse(AnnualReportRepresentation::of($report), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/annual/{year}/columns/{columnId}/explain', name: 'api_v1_reports_annual_explain', requirements: ['year' => '\d{1,9}', 'columnId' => '[^/]{1,80}'], methods: ['GET'])]
    public function explain(int $year, string $columnId, Request $request, BuildAnnualReport $build): Response
    {
        try {
            $explanation = $build->explain($year, $columnId, self::incompleteMonths($request));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse(AnnualReportRepresentation::explanation($explanation), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function incompleteMonths(Request $request): ?string
    {
        return $request->query->has('incompleteMonths') ? $request->query->getString('incompleteMonths') : null;
    }

    private function failure(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof InvalidReportYear => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_report_year', self::TYPE_INVALID_YEAR),
            $exception instanceof InvalidAnnualReportQuery => $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_annual_report_query'),
            $exception instanceof AnnualColumnNotFound => $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.annual_column_not_found'),
            $exception instanceof MonthlyProjectionScopeTooLarge => $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.monthly_projection_scope_too_large',
                MonthlyProjectionController::TYPE_SCOPE_TOO_LARGE,
            ),
            $exception instanceof WorkspaceAccessDenied => $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden'),
            default => throw $exception,
        };
    }

    private function problem(int $status, string $key, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response($status, $this->translator->trans($key.'.title'), $this->translator->trans($key.'.detail'), $type);
    }
}
