<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\Performance\PerformanceRangeTooDense;
use App\Module\Portfolio\Application\Performance\ReadPortfolioPerformance;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\Cash\CashReplayFailed;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PortfolioPerformanceController
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-performance', name: 'api_v1_portfolio_performance', methods: ['GET'])]
    public function performance(string $accountId, Request $request, ReadPortfolioPerformance $read): Response
    {
        try {
            $response = new JsonResponse(PortfolioPerformanceRepresentation::of(
                $read(self::identifier($accountId), self::scalar($request, 'scope', 'invalid_performance_scope'), self::scalar($request, 'period', 'invalid_performance_period')),
            ));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (\Throwable $exception) {
            [$status, $code] = match (true) {
                $exception instanceof PortfolioAccountNotFound => [404, 'portfolio_account_not_found'],
                $exception instanceof InvalidPortfolioOperation => [422, $exception->problemCode],
                $exception instanceof PerformanceRangeTooDense => [422, 'performance_range_too_dense'],
                $exception instanceof CashReplayFailed => [422, 'cash_amount_too_large'],
                $exception instanceof PositionReplayFailed => [422, $exception->problemCode],
                $exception instanceof WorkspaceAccessDenied => [403, 'portfolio_forbidden'],
                default => throw $exception,
            };

            return ApiProblem::response($status, $this->translator->trans('api.problem.'.$code.'.title'), $this->translator->trans('api.problem.'.$code.'.detail'), extensions: ['code' => $code]);
        }
    }

    private static function identifier(string $literal): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $literal)) {
            throw new PortfolioAccountNotFound();
        }

        return $literal;
    }

    private static function scalar(Request $request, string $key, string $code): ?string
    {
        $value = $request->query->all()[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidPortfolioOperation('A query parameter must be a string.', $code);
        }

        return $value;
    }
}
