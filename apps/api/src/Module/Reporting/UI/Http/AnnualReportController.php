<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Reporting\Application\BuildAnnualReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AnnualReportController
{
    public const string TYPE_INVALID_YEAR = '/problems/invalid-report-year';

    public function __construct(private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/reports/annual/{year}', name: 'api_v1_reports_annual', requirements: ['year' => '\d{1,9}'], methods: ['GET'])]
    public function __invoke(int $year, Request $request, BuildAnnualReport $build): Response
    {
        try {
            $report = $build($year, self::incompleteMonths($request));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ReportingProblems::annual());
        }

        return new JsonResponse(AnnualReportRepresentation::of($report), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/annual/{year}/columns/{columnId}/explain', name: 'api_v1_reports_annual_explain', requirements: ['year' => '\d{1,9}', 'columnId' => '[^/]{1,80}'], methods: ['GET'])]
    public function explain(int $year, string $columnId, Request $request, BuildAnnualReport $build): Response
    {
        try {
            $explanation = $build->explain($year, $columnId, self::incompleteMonths($request));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ReportingProblems::annual());
        }

        return new JsonResponse(AnnualReportRepresentation::explanation($explanation), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function incompleteMonths(Request $request): ?string
    {
        return $request->query->has('incompleteMonths') ? $request->query->getString('incompleteMonths') : null;
    }
}
