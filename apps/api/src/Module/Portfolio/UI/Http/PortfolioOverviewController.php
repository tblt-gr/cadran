<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\CashRangeTooDense;
use App\Module\Portfolio\Application\ListPortfolios;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\ReadMonthEndValuationReminders;
use App\Module\Portfolio\Application\ReadPortfolioAllocation;
use App\Module\Portfolio\Application\ReadPortfolioSummary;
use App\Module\Portfolio\Domain\Cash\CashReplayFailed;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use App\Module\Transactions\Application\AccountTransactionScopeTooLarge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PortfolioOverviewController
{
    public function __construct(private TranslatorInterface $translator)
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
        return $this->execute(static fn (): array => $read(self::identifier($accountId), self::scalar($request, 'asOf', 'invalid_as_of')));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-allocation', name: 'api_v1_portfolio_allocation', methods: ['GET'])]
    public function allocation(string $accountId, Request $request, ReadPortfolioAllocation $read): Response
    {
        return $this->execute(static fn (): array => $read(self::identifier($accountId), self::scalar($request, 'asOf', 'invalid_as_of'), self::scalar($request, 'by', 'invalid_allocation_by')));
    }

    /** @param callable(): array<string, mixed> $read */
    private function execute(callable $read): Response
    {
        try {
            $response = new JsonResponse($read());
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (\Throwable $exception) {
            [$status, $code] = match (true) {
                $exception instanceof PortfolioAccountNotFound => [404, 'portfolio_account_not_found'],
                $exception instanceof InvalidPortfolioOperation => [422, $exception->problemCode],
                $exception instanceof CashRangeTooDense, $exception instanceof AccountTransactionScopeTooLarge => [422, 'cash_range_too_dense'],
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
