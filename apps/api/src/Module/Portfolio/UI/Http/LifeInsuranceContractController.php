<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\LifeInsurance\AddFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceContract;
use App\Module\Portfolio\Application\LifeInsurance\RecordEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\SaveLifeInsuranceContract;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LifeInsuranceContractController
{
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';

    public function __construct(private TranslatorInterface $translator, private JsonRequestEnvelope $requestEnvelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-income-and-fees', name: 'api_v1_life_insurance_income_and_fees', methods: ['GET'])]
    public function incomeAndFees(string $accountId, Request $request, \App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceIncomeAndFees $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $year = $request->query->all()['year'] ?? null;
            if (!is_string($year)) {
                throw new InvalidPortfolioOperation('A year is required.', 'invalid_year');
            }

            return self::json($read($accountId, $year));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_show', methods: ['GET'])]
    public function show(string $accountId, ReadLifeInsuranceContract $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $overview = $read($accountId);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return self::json(LifeInsuranceRepresentation::overview($overview));
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_save', methods: ['PUT'])]
    public function save(string $accountId, Request $request, SaveLifeInsuranceContract $save): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $saved = $save($accountId, LifeInsurancePayload::contract($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return self::json(LifeInsuranceRepresentation::contract($saved->contract), $saved->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract/fee-terms', name: 'api_v1_life_insurance_fee_terms_add', methods: ['POST'])]
    public function addFeeTerms(string $accountId, Request $request, AddFeeTerms $add): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $recorded = $add($accountId, LifeInsurancePayload::feeTerms($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return self::json(LifeInsuranceRepresentation::feeTerms($recorded->terms, $recorded->assetCode), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-fee-terms/{id}', name: 'api_v1_life_insurance_fee_terms_remove', methods: ['DELETE'])]
    public function removeFeeTerms(string $id, RemoveFeeTerms $remove): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'life_insurance_fee_terms_not_found');
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
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $rate = $record($accountId, LifeInsurancePayload::euroFundRate($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return self::json(LifeInsuranceRepresentation::rate($rate), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-euro-fund-rates/{id}', name: 'api_v1_life_insurance_euro_fund_rates_remove', methods: ['DELETE'])]
    public function removeRate(string $id, RemoveEuroFundRate $remove): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'euro_fund_rate_not_found');
        }
        try {
            $remove($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::lifeInsurance());
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    /** @return array<mixed>|Response */
    private function readBody(Request $request): array|Response
    {
        return $this->requestEnvelope->body(
            $request,
            BodyLimit::Standard,
            'api.problem.portfolio_payload_too_large',
            fn (int $status, string $translationKey): JsonResponse => $this->problem($status, substr($translationKey, strlen('api.problem.'))),
        );
    }

    private function problem(int $status, string $code): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans('api.problem.'.$code.'.title'),
            $this->translator->trans('api.problem.'.$code.'.detail'),
            '/problems/'.str_replace('_', '-', $code),
            extensions: ['code' => $code],
        );
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
