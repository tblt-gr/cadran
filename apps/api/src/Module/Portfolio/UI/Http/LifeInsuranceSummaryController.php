<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\CashRangeTooDense;
use App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceSummary;
use App\Module\Portfolio\Application\Performance\PerformanceRangeTooDense;
use App\Module\Portfolio\Application\Performance\PortfolioPerformance;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LifeInsuranceSummaryController
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-summary', name: 'api_v1_life_insurance_summary', methods: ['GET'])]
    public function __invoke(string $accountId, Request $request, ReadLifeInsuranceSummary $read): Response
    {
        try {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $accountId)) {
                throw new PortfolioAccountNotFound();
            }
            $asOf = $request->query->all()['asOf'] ?? null;
            if (null !== $asOf && !is_string($asOf)) {
                throw new InvalidPortfolioOperation('The summary date is a string.', 'invalid_summary_date');
            }
            $summary = $read($accountId, $asOf);
            $contract = $summary['contract'];
            $performance = $summary['gainSinceOpening'];
            if (!$performance instanceof PortfolioPerformance || (null !== $contract && !$contract instanceof LifeInsuranceContract)) {
                throw new \LogicException('The summary includes its contract and performance views.');
            }
            $summary['contract'] = null === $contract ? null : LifeInsuranceRepresentation::contract($contract);
            $summary['gainSinceOpening'] = PortfolioPerformanceRepresentation::of($performance)['gain'];
            $response = new JsonResponse($summary);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (\Throwable $exception) {
            [$status, $code] = match (true) {
                $exception instanceof PortfolioAccountNotFound => [404, 'portfolio_account_not_found'],
                $exception instanceof InvalidPortfolioOperation => [422, $exception->problemCode],
                $exception instanceof CashRangeTooDense, $exception instanceof PerformanceRangeTooDense => [422, 'cash_range_too_dense'],
                $exception instanceof \App\Module\Portfolio\Domain\Cash\CashReplayFailed => [422, 'cash_amount_too_large'],
                $exception instanceof \App\Module\Portfolio\Domain\Positions\PositionReplayFailed => [422, $exception->problemCode],
                $exception instanceof WorkspaceAccessDenied => [403, 'portfolio_forbidden'],
                default => throw $exception,
            };

            return ApiProblem::response($status, $this->translator->trans('api.problem.'.$code.'.title'),
                $this->translator->trans('api.problem.'.$code.'.detail'), extensions: ['code' => $code]);
        }
    }
}
