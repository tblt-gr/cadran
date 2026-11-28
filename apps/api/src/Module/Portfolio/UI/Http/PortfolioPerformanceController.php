<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\Performance\ReadPortfolioAnnualPerformance;
use App\Module\Portfolio\Application\Performance\ReadPortfolioPerformance;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioPerformanceController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-performance', name: 'api_v1_portfolio_performance', methods: ['GET'])]
    public function performance(string $accountId, Request $request, ReadPortfolioPerformance $read): Response
    {
        return $this->respond(static fn (): array => PortfolioPerformanceRepresentation::of(
            $read(PortfolioQueryParameters::accountId($accountId), PortfolioQueryParameters::string($request, 'scope', 'invalid_performance_scope'), PortfolioQueryParameters::string($request, 'period', 'invalid_performance_period')),
        ));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-performance/annual', name: 'api_v1_portfolio_performance_annual', methods: ['GET'])]
    public function annual(string $accountId, Request $request, ReadPortfolioAnnualPerformance $read): Response
    {
        return $this->respond(static fn (): array => PortfolioAnnualPerformanceRepresentation::of(
            $read(PortfolioQueryParameters::accountId($accountId), PortfolioQueryParameters::string($request, 'year', 'invalid_performance_period')),
        ));
    }

    /** @param \Closure(): array<string, mixed> $body */
    private function respond(\Closure $body): Response
    {
        try {
            return $this->envelope->json($body());
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioReadProblems::performance());
        }
    }
}
