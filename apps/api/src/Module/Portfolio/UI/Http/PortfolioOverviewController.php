<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\ListPortfolios;
use App\Module\Portfolio\Application\ReadMonthEndValuationReminders;
use App\Module\Portfolio\Application\ReadPortfolioAllocation;
use App\Module\Portfolio\Application\ReadPortfolioSummary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioOverviewController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/portfolios', name: 'api_v1_portfolios', methods: ['GET'])]
    public function list(ListPortfolios $list): Response
    {
        return $this->execute($list(...));
    }

    #[Route('/api/v1/portfolio-valuation-reminders', name: 'api_v1_portfolio_valuation_reminders', methods: ['GET'])]
    public function reminders(CallerWorkspaceContext $caller, ReadMonthEndValuationReminders $read): Response
    {
        return $this->execute(static fn (): array => $read($caller->resolveContext()->workspace));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-summary', name: 'api_v1_portfolio_summary', methods: ['GET'])]
    public function summary(string $accountId, Request $request, ReadPortfolioSummary $read): Response
    {
        return $this->execute(static fn (): array => $read(PortfolioQueryParameters::accountId($accountId), PortfolioQueryParameters::string($request, 'asOf', 'invalid_as_of')));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-allocation', name: 'api_v1_portfolio_allocation', methods: ['GET'])]
    public function allocation(string $accountId, Request $request, ReadPortfolioAllocation $read): Response
    {
        return $this->execute(static fn (): array => $read(PortfolioQueryParameters::accountId($accountId), PortfolioQueryParameters::string($request, 'asOf', 'invalid_as_of'), PortfolioQueryParameters::string($request, 'by', 'invalid_allocation_by')));
    }

    /** @param callable(): array<string, mixed> $read */
    private function execute(callable $read): Response
    {
        try {
            return $this->envelope->json($read());
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioReadProblems::summary());
        }
    }
}
