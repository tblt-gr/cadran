<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reporting\Application\InvalidMonthlyProjectionQuery;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\ReadMonthlyRecap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyRecapController
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/monthly/recap', name: 'api_v1_reports_monthly_recap', methods: ['GET'])]
    public function __invoke(Request $request, ReadMonthlyRecap $read): Response
    {
        $month = $request->query->getString('month');
        if (1 !== preg_match('/^[0-9]{4}-[0-9]{2}$/D', $month)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        }

        try {
            $recap = $read($month);
        } catch (InvalidMonthlyProjectionQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_projection_query');
        } catch (MonthlyProjectionScopeTooLarge) {
            return $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.monthly_projection_scope_too_large',
                MonthlyProjectionController::TYPE_SCOPE_TOO_LARGE,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(
            MonthlyRecapRepresentation::of($recap),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    private function problem(int $status, string $key, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
            $type,
        );
    }
}
