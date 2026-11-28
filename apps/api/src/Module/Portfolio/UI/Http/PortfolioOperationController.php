<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\CorrectPortfolioOperation;
use App\Module\Portfolio\Application\ListPortfolioOperations;
use App\Module\Portfolio\Application\ListPortfolioOperationsQuery;
use App\Module\Portfolio\Application\ListTransferCandidates;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioOperationNotFound;
use App\Module\Portfolio\Application\PreviewPortfolioOperation;
use App\Module\Portfolio\Application\ReadPortfolioOperation;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\VoidPortfolioOperation;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use App\Module\Transactions\UI\Http\TransactionProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioOperationController
{
    public function __construct(
        private JsonRequestEnvelope $envelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $idempotency,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, ListPortfolioOperations $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::operation());
        }
        try {
            $page = $list($accountId, new ListPortfolioOperationsQuery(
                from: PortfolioQueryParameters::string($request, 'from', 'invalid_operation_query'),
                to: PortfolioQueryParameters::string($request, 'to', 'invalid_operation_query'),
                types: self::repeated($request, 'type'),
                instrumentId: PortfolioQueryParameters::string($request, 'instrumentId', 'invalid_operation_query'),
                includeVoided: PortfolioQueryParameters::string($request, 'includeVoided', 'invalid_operation_query'),
                page: PortfolioQueryParameters::string($request, 'page', 'invalid_operation_query'),
                pageSize: PortfolioQueryParameters::string($request, 'pageSize', 'invalid_operation_query'),
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json(PortfolioOperationRepresentation::page($page));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations/preview', name: 'api_v1_portfolio_operations_preview', methods: ['POST'])]
    public function preview(string $accountId, Request $request, PreviewPortfolioOperation $preview): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::operation());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
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

        return $this->envelope->json(PortfolioOperationRepresentation::preview($result));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations', name: 'api_v1_portfolio_operations_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, RecordPortfolioOperation $record): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::operation());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = OperationPayload::operation($body);
            $result = $this->idempotentExecution->execute(
                'portfolio.operation.create',
                $this->idempotency->idempotency($request, false),
                static function () use ($record, $accountId, $input): IdempotentResponse {
                    $view = $record($accountId, $input);

                    return new IdempotentResponse(PortfolioOperationRepresentation::one($view), Response::HTTP_CREATED, $view->id, [$view->tradeDate]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, [...TransactionProblems::idempotency(), ...PortfolioProblems::operation()]);
        }

        return $this->idempotency->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/portfolio-operations/{id}', name: 'api_v1_portfolio_operations_show', methods: ['GET'])]
    public function show(string $id, ReadPortfolioOperation $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new PortfolioOperationNotFound(), PortfolioProblems::operation());
        }
        try {
            $view = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/void', name: 'api_v1_portfolio_operations_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidPortfolioOperation $void): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new PortfolioOperationNotFound(), PortfolioProblems::operation());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, ['version'], ['reason']);
            $view = $void($id, $payload->integer('version'), $payload->nullableString('reason'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json(PortfolioOperationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-operations/{id}/correct', name: 'api_v1_portfolio_operations_correct', methods: ['POST'])]
    public function correct(string $id, Request $request, CorrectPortfolioOperation $correct): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new PortfolioOperationNotFound(), PortfolioProblems::operation());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, ['version', 'operation'], ['reason']);
            $version = $payload->integer('version');
            $reason = $payload->nullableString('reason');
            $operation = $payload->raw('operation');
            if (!is_array($operation)) {
                throw new MalformedPayload('operation must be an object.');
            }
            $input = OperationPayload::operation($operation);
            $result = $this->idempotentExecution->execute(
                'portfolio.operation.correct',
                $this->idempotency->idempotency($request, false),
                static function () use ($correct, $id, $version, $reason, $input): IdempotentResponse {
                    $correction = $correct($id, $version, $reason, $input);
                    $view = $correction->replacement;

                    return new IdempotentResponse(PortfolioOperationRepresentation::one($view), Response::HTTP_CREATED, $view->id, [$correction->originalTradeDate, $view->tradeDate]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, [...TransactionProblems::idempotency(), ...PortfolioProblems::operation()]);
        }

        return $this->idempotency->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-transfer-candidates', name: 'api_v1_portfolio_transfer_candidates', methods: ['GET'])]
    public function candidates(string $accountId, Request $request, ListTransferCandidates $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::operation());
        }
        try {
            $candidates = $list($accountId, $request->query->getString('direction'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::operation());
        }

        return $this->envelope->json(PortfolioOperationRepresentation::candidates($candidates));
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
