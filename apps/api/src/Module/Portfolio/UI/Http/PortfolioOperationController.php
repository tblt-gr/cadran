<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\CorrectPortfolioOperation;
use App\Module\Portfolio\Application\InvalidOperationQuery;
use App\Module\Portfolio\Application\ListPortfolioOperations;
use App\Module\Portfolio\Application\ListPortfolioOperationsQuery;
use App\Module\Portfolio\Application\ListTransferCandidates;
use App\Module\Portfolio\Application\PortfolioOperationNotFound;
use App\Module\Portfolio\Application\PreviewPortfolioOperation;
use App\Module\Portfolio\Application\ReadPortfolioOperation;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\VoidPortfolioOperation;
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

final readonly class PortfolioOperationController
{
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';
    private const string OPERATION_NOT_FOUND = 'portfolio_operation_not_found';

    public function __construct(
        private TranslatorInterface $translator,
        private JsonRequestEnvelope $requestEnvelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, ListPortfolioOperations $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $query = $request->query;
        try {
            $page = $list($accountId, new ListPortfolioOperationsQuery(
                from: self::scalar($query->all(), 'from'),
                to: self::scalar($query->all(), 'to'),
                types: self::repeated($request, 'type'),
                instrumentId: self::scalar($query->all(), 'instrumentId'),
                includeVoided: self::scalar($query->all(), 'includeVoided'),
                page: self::scalar($query->all(), 'page'),
                pageSize: self::scalar($query->all(), 'pageSize'),
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return self::json(PortfolioOperationRepresentation::page($page));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations/preview', name: 'api_v1_portfolio_operations_preview', methods: ['POST'])]
    public function preview(string $accountId, Request $request, PreviewPortfolioOperation $preview): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $correctingOperationId = $request->query->get('correctingOperationId');
            if (null !== $correctingOperationId && !Identifier::isValid($correctingOperationId)) {
                throw new PortfolioOperationNotFound();
            }
            $result = $preview($accountId, OperationPayload::operation($body), $correctingOperationId);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return self::json(PortfolioOperationRepresentation::preview($result));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, RecordPortfolioOperation $record): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = OperationPayload::operation($body);
            $result = $this->idempotentExecution->execute(
                'portfolio.operation.create',
                $this->envelope->idempotency($request, false),
                static function () use ($record, $accountId, $input): IdempotentResponse {
                    $view = $record($accountId, $input);

                    return new IdempotentResponse(PortfolioOperationRepresentation::one($view), Response::HTTP_CREATED, $view->id, [$view->tradeDate]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/portfolio-operations/{id}', name: 'api_v1_portfolio_operations_show', methods: ['GET'])]
    public function show(string $id, ReadPortfolioOperation $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        try {
            $view = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return self::json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/void', name: 'api_v1_portfolio_operations_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidPortfolioOperation $void): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fields = OperationPayload::shape($body, ['version'], ['reason']);
            $reason = $fields['reason'] ?? null;
            if (!is_int($fields['version']) || (null !== $reason && !is_string($reason))) {
                throw new \UnexpectedValueException('version is an integer and reason a string.');
            }
            $view = $void($id, $fields['version'], $reason);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return self::json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/correct', name: 'api_v1_portfolio_operations_correct', methods: ['POST'])]
    public function correct(string $id, Request $request, CorrectPortfolioOperation $correct): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fields = OperationPayload::shape($body, ['version', 'operation'], ['reason']);
            $reason = $fields['reason'] ?? null;
            if (!is_int($fields['version']) || (null !== $reason && !is_string($reason)) || !is_array($fields['operation'])) {
                throw new \UnexpectedValueException('version is an integer, reason a string and operation an object.');
            }
            $input = OperationPayload::operation($fields['operation']);
            $version = $fields['version'];
            $result = $this->idempotentExecution->execute(
                'portfolio.operation.correct',
                $this->envelope->idempotency($request, false),
                static function () use ($correct, $id, $version, $reason, $input): IdempotentResponse {
                    $correction = $correct($id, $version, $reason, $input);
                    $view = $correction->replacement;

                    return new IdempotentResponse(PortfolioOperationRepresentation::one($view), Response::HTTP_CREATED, $view->id, [$correction->originalTradeDate, $view->tradeDate]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-transfer-candidates', name: 'api_v1_portfolio_transfer_candidates', methods: ['GET'])]
    public function candidates(string $accountId, Request $request, ListTransferCandidates $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $candidates = $list($accountId, $request->query->getString('direction'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return self::json(PortfolioOperationRepresentation::candidates($candidates));
    }

    /** Maps what a use case refuses to its problem; anything unexpected keeps propagating to the kernel. */
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
            throw new InvalidOperationQuery('A query parameter is a single value.');
        }

        return $value;
    }

    /**
     * Repeatable filters travel as plain repeated keys (`?type=BUY&type=SELL`); PHP's own
     * query parsing keeps only the last one, so the raw query string is read instead.
     *
     * @return list<string>
     */
    private static function repeated(Request $request, string $key): array
    {
        $queryString = $request->server->get('QUERY_STRING');
        if (!is_string($queryString) || '' === $queryString) {
            return [];
        }
        $values = [];
        foreach (explode('&', $queryString) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = preg_replace('/\[[^\]]*]$/', '', rawurldecode(str_replace('+', ' ', $rawKey))) ?? $rawKey;
            if ($name === $key) {
                $values[] = rawurldecode(str_replace('+', ' ', $rawValue));
            }
        }

        return $values;
    }
}
