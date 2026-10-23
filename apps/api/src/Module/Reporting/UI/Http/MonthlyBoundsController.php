<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reporting\Application\ReadMonthlyBounds;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyBoundsController
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/monthly/bounds', name: 'api_v1_reports_monthly_bounds', methods: ['GET'])]
    public function __invoke(ReadMonthlyBounds $read): Response
    {
        try {
            $view = $read();
        } catch (WorkspaceAccessDenied) {
            return ApiProblem::response(
                Response::HTTP_FORBIDDEN,
                $this->translator->trans('api.problem.monthly_projection_forbidden.title'),
                $this->translator->trans('api.problem.monthly_projection_forbidden.detail'),
                ApiProblem::TYPE_BLANK,
            );
        }

        return new JsonResponse(
            ['firstDataMonth' => $view->firstDataMonth, 'currentMonth' => $view->currentMonth],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
