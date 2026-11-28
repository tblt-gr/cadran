<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\LifeInsurance\AddFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceNotFound;
use App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceContract;
use App\Module\Portfolio\Application\LifeInsurance\RecordEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\SaveLifeInsuranceContract;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LifeInsuranceContractController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-income-and-fees', name: 'api_v1_life_insurance_income_and_fees', methods: ['GET'])]
    public function incomeAndFees(string $accountId, Request $request, \App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceIncomeAndFees $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::lifeInsurance());
        }
        try {
            $year = $request->query->all()['year'] ?? null;
            if (!is_string($year)) {
                throw new InvalidPortfolioOperation('A year is required.', 'invalid_year');
            }

            return $this->envelope->json($read($accountId, $year));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_show', methods: ['GET'])]
    public function show(string $accountId, ReadLifeInsuranceContract $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::lifeInsurance());
        }
        try {
            $overview = $read($accountId);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return $this->envelope->json(LifeInsuranceRepresentation::overview($overview));
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_save', methods: ['PUT'])]
    public function save(string $accountId, Request $request, SaveLifeInsuranceContract $save): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::lifeInsurance());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $saved = $save($accountId, LifeInsurancePayload::contract($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return $this->envelope->json(LifeInsuranceRepresentation::contract($saved->contract), $saved->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract/fee-terms', name: 'api_v1_life_insurance_fee_terms_add', methods: ['POST'])]
    public function addFeeTerms(string $accountId, Request $request, AddFeeTerms $add): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::lifeInsurance());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $recorded = $add($accountId, LifeInsurancePayload::feeTerms($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return $this->envelope->json(LifeInsuranceRepresentation::feeTerms($recorded->terms, $recorded->assetCode), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-fee-terms/{id}', name: 'api_v1_life_insurance_fee_terms_remove', methods: ['DELETE'])]
    public function removeFeeTerms(string $id, RemoveFeeTerms $remove): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new LifeInsuranceNotFound('life_insurance_fee_terms_not_found'), PortfolioProblems::lifeInsurance());
        }
        try {
            $remove($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract/euro-fund-rates', name: 'api_v1_life_insurance_euro_fund_rates_record', methods: ['POST'])]
    public function recordRate(string $accountId, Request $request, RecordEuroFundRate $record): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::lifeInsurance());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $rate = $record($accountId, LifeInsurancePayload::euroFundRate($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return $this->envelope->json(LifeInsuranceRepresentation::rate($rate), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-euro-fund-rates/{id}', name: 'api_v1_life_insurance_euro_fund_rates_remove', methods: ['DELETE'])]
    public function removeRate(string $id, RemoveEuroFundRate $remove): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new LifeInsuranceNotFound('euro_fund_rate_not_found'), PortfolioProblems::lifeInsurance());
        }
        try {
            $remove($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}
