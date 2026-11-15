<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Reporting\Application\ReadMultiYearReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MultiYearReportController
{
    public const string TYPE_INVALID_YEAR_RANGE = '/problems/invalid-year-range';
    public const string TYPE_RANGE_TOO_LARGE = '/problems/range-too-large';

    public function __construct(private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/reports/multi-year', name: 'api_v1_reports_multi_year', methods: ['GET'])]
    public function __invoke(Request $request, ReadMultiYearReport $read): Response
    {
        try {
            $report = $read(
                self::query($request, 'from'),
                self::query($request, 'to'),
                self::query($request, 'incompleteMonths'),
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ReportingProblems::multiYear());
        }

        return new JsonResponse(MultiYearReportRepresentation::of($report), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function query(Request $request, string $name): ?string
    {
        return $request->query->has($name) ? $request->query->getString($name) : null;
    }
}
