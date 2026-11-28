<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\BuildValuationDraft;
use App\Module\Portfolio\Application\ListPortfolioValuations;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\ReadPortfolioValuation;
use App\Module\Portfolio\Application\ReadPortfolioValuationHistory;
use App\Module\Portfolio\Application\RecordPortfolioValuation;
use App\Module\Portfolio\Application\ValuationNotFound;
use App\Module\Portfolio\Application\WithdrawPortfolioValuation;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use App\Module\Transactions\UI\Http\TransactionProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioValuationController
{
    public function __construct(
        private JsonRequestEnvelope $envelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $idempotency,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations', name: 'api_v1_portfolio_valuations_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, ListPortfolioValuations $list): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::valuation());
        }
        try {
            $page = $list(
                $accountId,
                PortfolioQueryParameters::string($request, 'from', 'invalid_valuation_query'),
                PortfolioQueryParameters::string($request, 'to', 'invalid_valuation_query'),
                PortfolioQueryParameters::string($request, 'page', 'invalid_valuation_query'),
                PortfolioQueryParameters::string($request, 'pageSize', 'invalid_valuation_query'),
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json(ValuationRepresentation::page($page));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuation-history', name: 'api_v1_portfolio_valuation_history', methods: ['GET'])]
    public function history(string $accountId, Request $request, ReadPortfolioValuationHistory $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::valuation());
        }
        try {
            $history = $read($accountId, PortfolioQueryParameters::string($request, 'from', 'invalid_history_range'), PortfolioQueryParameters::string($request, 'to', 'invalid_history_range'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json(ValuationRepresentation::history($history));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations/draft', name: 'api_v1_portfolio_valuations_draft', methods: ['GET'])]
    public function draft(string $accountId, Request $request, BuildValuationDraft $build): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::valuation());
        }
        try {
            $draft = $build($accountId, PortfolioQueryParameters::string($request, 'valuedOn', 'invalid_valuation_query'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json(ValuationRepresentation::draft($draft));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-valuations', name: 'api_v1_portfolio_valuations_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, RecordPortfolioValuation $record): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::valuation());
        }
        $body = $this->envelope->body($request, BodyLimit::XLarge, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = ValuationPayload::valuation($body);
            $result = $this->idempotentExecution->execute(
                'portfolio.valuation.record',
                $this->idempotency->idempotency($request, false),
                static function () use ($record, $accountId, $input): IdempotentResponse {
                    $view = $record($accountId, $input);

                    return new IdempotentResponse(ValuationRepresentation::one($view), Response::HTTP_CREATED, $view->valuation->id, [$view->valuation->valuedOn->format('Y-m-d')]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, [...TransactionProblems::idempotency(), ...PortfolioProblems::valuation()]);
        }

        return $this->idempotency->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/portfolio-valuations/{id}', name: 'api_v1_portfolio_valuations_show', methods: ['GET'])]
    public function show(string $id, ReadPortfolioValuation $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new ValuationNotFound(), PortfolioProblems::valuation());
        }
        try {
            $view = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json(ValuationRepresentation::one($view));
    }

    #[Route('/api/v1/portfolio-valuations/{id}/withdraw', name: 'api_v1_portfolio_valuations_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request, WithdrawPortfolioValuation $withdraw): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new ValuationNotFound(), PortfolioProblems::valuation());
        }
        $body = $this->envelope->body($request, BodyLimit::XLarge, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $view = $withdraw($id, JsonPayload::of($body, ['version'])->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::valuation());
        }

        return $this->envelope->json(ValuationRepresentation::one($view));
    }
}
