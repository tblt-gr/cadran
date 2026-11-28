<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\IncludeUpcomingQuery;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Reporting\Application\InvalidMonthlyKpiExplanationQuery;
use App\Module\Reporting\Application\InvalidMonthlyProjectionQuery;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\ReadMonthlyKpiExplanation;
use App\Module\Reporting\Application\ReadMonthlyProjection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MonthlyProjectionController
{
    public const string TYPE_SCOPE_TOO_LARGE = '/problems/monthly-projection-scope-too-large';

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/reports/monthly', name: 'api_v1_reports_monthly', methods: ['GET'])]
    public function __invoke(Request $request, ReadMonthlyProjection $read): Response
    {
        $month = $request->query->getString('month');
        if (1 !== preg_match('/^[0-9]{4}-[0-9]{2}$/D', $month)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        }

        $includeUpcoming = IncludeUpcomingQuery::of($request);
        if (null === $includeUpcoming) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        }

        try {
            $projection = $read($month, $includeUpcoming);
        } catch (InvalidMonthlyProjectionQuery) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        } catch (MonthlyProjectionScopeTooLarge) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.monthly_projection_scope_too_large',
                self::TYPE_SCOPE_TOO_LARGE,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(
            MonthlyProjectionRepresentation::of($projection),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    #[Route('/api/v1/reports/monthly/{kpi}/explain', name: 'api_v1_reports_monthly_explain', methods: ['GET'])]
    public function explain(string $kpi, Request $request, ReadMonthlyKpiExplanation $read): Response
    {
        $month = $request->query->getString('month');
        if (1 !== preg_match('/^[0-9]{4}-[0-9]{2}$/D', $month)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        }

        $includeUpcoming = IncludeUpcomingQuery::of($request);
        if (null === $includeUpcoming) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        }

        try {
            $explanation = $read($month, $kpi, $includeUpcoming);
        } catch (InvalidMonthlyKpiExplanationQuery|InvalidMonthlyProjectionQuery) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        } catch (MonthlyProjectionScopeTooLarge) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.monthly_projection_scope_too_large',
                self::TYPE_SCOPE_TOO_LARGE,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(
            MonthlyKpiExplanationRepresentation::of($explanation),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
