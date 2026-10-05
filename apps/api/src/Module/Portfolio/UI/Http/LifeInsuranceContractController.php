<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\LifeInsurance\AddFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceConflict;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceNotFound;
use App\Module\Portfolio\Application\LifeInsurance\ReadLifeInsuranceContract;
use App\Module\Portfolio\Application\LifeInsurance\RecordEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\RemoveFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\SaveLifeInsuranceContract;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LifeInsuranceContractController
{
    private const int MAX_BODY_BYTES = 16_384;
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_show', methods: ['GET'])]
    public function show(string $accountId, ReadLifeInsuranceContract $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $overview = $read($accountId);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(LifeInsuranceRepresentation::overview($overview));
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract', name: 'api_v1_life_insurance_contract_save', methods: ['PUT'])]
    public function save(string $accountId, Request $request, SaveLifeInsuranceContract $save): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $saved = $save($accountId, LifeInsurancePayload::contract($body));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(LifeInsuranceRepresentation::contract($saved->contract), $saved->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract/fee-terms', name: 'api_v1_life_insurance_fee_terms_add', methods: ['POST'])]
    public function addFeeTerms(string $accountId, Request $request, AddFeeTerms $add): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $recorded = $add($accountId, LifeInsurancePayload::feeTerms($body));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(LifeInsuranceRepresentation::feeTerms($recorded->terms, $recorded->assetCode), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-fee-terms/{id}', name: 'api_v1_life_insurance_fee_terms_remove', methods: ['DELETE'])]
    public function removeFeeTerms(string $id, RemoveFeeTerms $remove): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'life_insurance_fee_terms_not_found');
        }
        try {
            $remove($id);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/accounts/{accountId}/life-insurance-contract/euro-fund-rates', name: 'api_v1_life_insurance_euro_fund_rates_record', methods: ['POST'])]
    public function recordRate(string $accountId, Request $request, RecordEuroFundRate $record): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $rate = $record($accountId, LifeInsurancePayload::euroFundRate($body));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(LifeInsuranceRepresentation::rate($rate), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/life-insurance-euro-fund-rates/{id}', name: 'api_v1_life_insurance_euro_fund_rates_remove', methods: ['DELETE'])]
    public function removeRate(string $id, RemoveEuroFundRate $remove): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'euro_fund_rate_not_found');
        }
        try {
            $remove($id);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    private function failure(\Throwable $exception): Response
    {
        return match (true) {
            $exception instanceof InvalidLifeInsurance => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode),
            $exception instanceof InvalidPortfolioOperation => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode),
            $exception instanceof \UnexpectedValueException => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_life_insurance_payload'),
            $exception instanceof PortfolioAccountNotFound => $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND),
            $exception instanceof LifeInsuranceNotFound => $this->problem(Response::HTTP_NOT_FOUND, $exception->problemCode),
            $exception instanceof LifeInsuranceConflict => $this->problem(Response::HTTP_CONFLICT, $exception->problemCode),
            $exception instanceof WorkspaceAccessDenied => $this->problem(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
            default => throw $exception,
        };
    }

    /** @return array<mixed>|Response */
    private function body(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'portfolio_payload_too_large');
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_request');
        }
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

    private static function identifier(string $value): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $value);
    }
}
