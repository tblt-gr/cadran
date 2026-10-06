<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\CorrectPortfolioOperation;
use App\Module\Portfolio\Application\InvalidOperationQuery;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Application\ListPortfolioOperations;
use App\Module\Portfolio\Application\ListPortfolioOperationsQuery;
use App\Module\Portfolio\Application\ListTransferCandidates;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioOperationConflict;
use App\Module\Portfolio\Application\PortfolioOperationNotFound;
use App\Module\Portfolio\Application\PreviewPortfolioOperation;
use App\Module\Portfolio\Application\ReadPortfolioOperation;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\StalePortfolioOperationVersion;
use App\Module\Portfolio\Application\VoidPortfolioOperation;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
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
    private const int MAX_BODY_BYTES = 16_384;
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';
    private const string OPERATION_NOT_FOUND = 'portfolio_operation_not_found';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        private TranslatorInterface $translator,
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, ListPortfolioOperations $list): Response
    {
        if (!self::identifier($accountId)) {
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
                perPage: self::scalar($query->all(), 'perPage'),
            ));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioOperationRepresentation::page($page));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations/preview', name: 'api_v1_portfolio_operations_preview', methods: ['POST'])]
    public function preview(string $accountId, Request $request, PreviewPortfolioOperation $preview): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $correctingOperationId = $request->query->get('correctingOperationId');
            if (null !== $correctingOperationId && !self::identifier($correctingOperationId)) {
                throw new PortfolioOperationNotFound();
            }
            $result = $preview($accountId, OperationPayload::operation($body), $correctingOperationId);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioOperationRepresentation::preview($result));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, RecordPortfolioOperation $record): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
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
            return $this->failure($exception);
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/portfolio-operations/{id}', name: 'api_v1_portfolio_operations_show', methods: ['GET'])]
    public function show(string $id, ReadPortfolioOperation $read): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        try {
            $view = $read($id);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/void', name: 'api_v1_portfolio_operations_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidPortfolioOperation $void): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        $body = $this->body($request);
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
            return $this->failure($exception);
        }

        return self::json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/correct', name: 'api_v1_portfolio_operations_correct', methods: ['POST'])]
    public function correct(string $id, Request $request, CorrectPortfolioOperation $correct): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND);
        }
        $body = $this->body($request);
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
            return $this->failure($exception);
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-transfer-candidates', name: 'api_v1_portfolio_transfer_candidates', methods: ['GET'])]
    public function candidates(string $accountId, Request $request, ListTransferCandidates $list): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $candidates = $list($accountId, $request->query->getString('direction'));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioOperationRepresentation::candidates($candidates));
    }

    /** Maps what a use case refuses to its problem; anything unexpected keeps propagating to the kernel. */
    private function failure(\Throwable $exception): Response
    {
        return match (true) {
            $exception instanceof PositionReplayFailed => $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->problemCode,
                $exception->extensions,
            ),
            $exception instanceof InvalidPortfolioOperation => $this->problem(
                'operation_voided' === $exception->problemCode ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->problemCode,
                $exception->extensions,
            ),
            $exception instanceof \UnexpectedValueException => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_operation_payload'),
            $exception instanceof InvalidOperationQuery => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_operation_query'),
            $exception instanceof PortfolioAccountNotFound => $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND),
            $exception instanceof PortfolioOperationNotFound => $this->problem(Response::HTTP_NOT_FOUND, self::OPERATION_NOT_FOUND),
            $exception instanceof StalePortfolioOperationVersion => $this->problem(Response::HTTP_CONFLICT, 'stale_operation_version'),
            $exception instanceof LinkedTransferTaken => $this->problem(Response::HTTP_CONFLICT, 'linked_transfer_taken'),
            $exception instanceof PortfolioOperationConflict => $this->problem(Response::HTTP_CONFLICT, $exception->problemCode),
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

    private static function identifier(string $value): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $value);
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
