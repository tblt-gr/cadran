<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reporting\Application\InvalidMultiYearReportQuery;
use App\Module\Reporting\Application\InvalidYearRange;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\ReadMultiYearReport;
use App\Module\Reporting\Application\YearRangeTooLarge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MultiYearReportController
{
    public const string TYPE_INVALID_YEAR_RANGE = '/problems/invalid-year-range';
    public const string TYPE_RANGE_TOO_LARGE = '/problems/range-too-large';

    public function __construct(private TranslatorInterface $translator)
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
            return $this->failure($exception);
        }

        return new JsonResponse(MultiYearReportRepresentation::of($report), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function query(Request $request, string $name): ?string
    {
        return $request->query->has($name) ? $request->query->getString($name) : null;
    }

    private function failure(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof InvalidYearRange => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_year_range', self::TYPE_INVALID_YEAR_RANGE),
            $exception instanceof YearRangeTooLarge => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.range_too_large', self::TYPE_RANGE_TOO_LARGE),
            $exception instanceof InvalidMultiYearReportQuery => $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_multi_year_report_query'),
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
