<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceSummary;
use App\Module\Portfolio\Application\Performance\PortfolioPerformance;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LifeInsuranceSummaryController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-summary', name: 'api_v1_life_insurance_summary', methods: ['GET'])]
    public function __invoke(string $accountId, Request $request, ReadLifeInsuranceSummary $read): Response
    {
        try {
            $accountId = PortfolioQueryParameters::accountId($accountId);
            $asOf = PortfolioQueryParameters::string($request, 'asOf', 'invalid_summary_date');
            $summary = $read($accountId, $asOf);
            $contract = $summary['contract'];
            $performance = $summary['gainSinceOpening'];
            if (!$performance instanceof PortfolioPerformance || (null !== $contract && !$contract instanceof LifeInsuranceContract)) {
                throw new \LogicException('The summary includes its contract and performance views.');
            }
            $summary['contract'] = null === $contract ? null : LifeInsuranceRepresentation::contract($contract);
            $summary['gainSinceOpening'] = PortfolioPerformanceRepresentation::of($performance)['gain'];

            return $this->envelope->json($summary);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioReadProblems::summary());
        }
    }
}
