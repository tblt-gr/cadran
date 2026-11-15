<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\BuildValuationDraft;
use App\Module\Portfolio\Application\InvalidHistoryRange;
use App\Module\Portfolio\Application\InvalidValuationQuery;
use App\Module\Portfolio\Application\ListPortfolioValuations;
use App\Module\Portfolio\Application\ReadPortfolioValuation;
use App\Module\Portfolio\Application\ReadPortfolioValuationHistory;
use App\Module\Portfolio\Application\RecordPortfolioValuation;
use App\Module\Portfolio\Application\WithdrawPortfolioValuation;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PortfolioValuationController
{
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';
    private const string VALUATION_NOT_FOUND = 'portfolio_valuation_not_found';

    public function __construct(
        private TranslatorInterface $translator,
        private JsonRequestEnvelope $requestEnvelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations', name: 'api_v1_portfolio_valuations_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, ListPortfolioValuations $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $query = $request->query->all();
            $page = $list($accountId, self::scalar($query, 'from'), self::scalar($query, 'to'), self::scalar($query, 'page'), self::scalar($query, 'pageSize'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return self::json(ValuationRepresentation::page($page));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuation-history', name: 'api_v1_portfolio_valuation_history', methods: ['GET'])]
    public function history(string $accountId, Request $request, ReadPortfolioValuationHistory $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $query = $request->query->all();
            foreach (['from', 'to'] as $key) {
                if (isset($query[$key]) && !is_string($query[$key])) {
                    throw new InvalidHistoryRange('A query parameter is a single value.');
                }
            }
            $history = $read($accountId, self::scalar($query, 'from'), self::scalar($query, 'to'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return self::json(ValuationRepresentation::history($history));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations/draft', name: 'api_v1_portfolio_valuations_draft', methods: ['GET'])]
    public function draft(string $accountId, Request $request, BuildValuationDraft $build): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $draft = $build($accountId, self::scalar($request->query->all(), 'valuedOn'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return self::json(ValuationRepresentation::draft($draft));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations', name: 'api_v1_portfolio_valuations_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, RecordPortfolioValuation $record): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = ValuationPayload::valuation($body);
            $result = $this->idempotentExecution->execute(
                'portfolio.valuation.record',
                $this->envelope->idempotency($request, false),
                static function () use ($record, $accountId, $input): IdempotentResponse {
                    $view = $record($accountId, $input);

                    return new IdempotentResponse(ValuationRepresentation::one($view), Response::HTTP_CREATED, $view->valuation->id, [$view->valuation->valuedOn->format('Y-m-d')]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/portfolio-valuations/{id}', name: 'api_v1_portfolio_valuations_show', methods: ['GET'])]
    public function show(string $id, ReadPortfolioValuation $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::VALUATION_NOT_FOUND);
        }
        try {
            $view = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return self::json(ValuationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-valuations/{id}/withdraw', name: 'api_v1_portfolio_valuations_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request, WithdrawPortfolioValuation $withdraw): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::VALUATION_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fields = OperationPayload::shape($body, ['version']);
            if (!is_int($fields['version'])) {
                throw new \UnexpectedValueException('version is an integer.');
            }
            $view = $withdraw($id, $fields['version']);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return self::json(ValuationRepresentation::one($view));
    }

    /** @return array<mixed>|Response */
    private function readBody(Request $request): array|Response
    {
        return $this->requestEnvelope->body(
            $request,
            BodyLimit::XLarge,
            'api.problem.portfolio_payload_too_large',
            fn (int $status, string $translationKey): JsonResponse => $this->problem($status, substr($translationKey, strlen('api.problem.'))),
        );
    }

    /** @param array<string, mixed> $extensions */
    private function problem(int $status, string $code, array $extensions = []): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans('api.problem.'.$code.'.title'),
            $this->translator->trans('api.problem.'.$code.'.detail'),
            '/problems/'.str_replace('_', '-', $code),
            extensions: ['code' => $code, ...$extensions],
        );
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $query */
    private static function scalar(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidValuationQuery('A query parameter is a single value.');
        }

        return $value;
    }
}
