<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reporting\Application\InvalidMonthlyLedgerQuery;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\ReadMonthlyLedger;
use App\Module\Reporting\Application\ReadMonthlyLedgerMovements;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyLedgerController
{
    public const string TYPE_SCOPE_TOO_LARGE = '/problems/monthly-ledger-scope-too-large';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/monthly/ledger', name: 'api_v1_reports_monthly_ledger', methods: ['GET'])]
    public function summary(Request $request, ReadMonthlyLedger $read): Response
    {
        try {
            $view = $read($request->query->getString('month'), self::optional($request, 'axis'));
        } catch (InvalidMonthlyLedgerQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_ledger_query');
        } catch (MonthlyProjectionScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.monthly_ledger_scope_too_large', self::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(MonthlyLedgerRepresentation::summary($view), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route(
        '/api/v1/reports/monthly/ledger/{kind}/{id}/movements',
        name: 'api_v1_reports_monthly_ledger_movements',
        requirements: ['kind' => 'income|expense|account', 'id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    public function movements(
        string $kind,
        string $id,
        Request $request,
        ReadMonthlyLedgerMovements $read,
    ): Response {
        try {
            $page = $read(
                $kind,
                $id,
                $request->query->getString('month'),
                self::optional($request, 'axis'),
                self::optional($request, 'cursor'),
            );
        } catch (InvalidMonthlyLedgerQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_monthly_ledger_query');
        } catch (MonthlyProjectionScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.monthly_ledger_scope_too_large', self::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(MonthlyLedgerRepresentation::page($page), headers: ['Cache-Control' => 'no-store']);
    }

    private static function optional(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) && '' !== $value ? $value : null;
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
