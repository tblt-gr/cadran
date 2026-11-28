<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\InvalidPositionQuery;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioInstrumentNotFound;
use App\Module\Portfolio\Application\ReadPortfolioCash;
use App\Module\Portfolio\Application\ReadPortfolioLots;
use App\Module\Portfolio\Application\ReadPortfolioPositions;
use App\Module\Portfolio\Application\ReorderPortfolioOperations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioPositionController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-positions', name: 'api_v1_portfolio_positions_list', methods: ['GET'])]
    public function positions(string $accountId, Request $request, ReadPortfolioPositions $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::position());
        }
        try {
            $asOf = PortfolioQueryParameters::string($request, 'asOf', 'invalid_position_query');
            $includeClosedLiteral = PortfolioQueryParameters::string($request, 'includeClosed', 'invalid_position_query');
            $includeClosed = match ($includeClosedLiteral) {
                null, 'false' => false,
                'true' => true,
                default => throw new InvalidPositionQuery('includeClosed is a boolean.'),
            };
            $view = $read($accountId, $asOf, $includeClosed);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::position());
        }

        return $this->envelope->json(['asOf' => $view->asOf, 'valuedOn' => $view->valuedOn, 'items' => $view->items]);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-cash', name: 'api_v1_portfolio_cash', methods: ['GET'])]
    public function cash(string $accountId, Request $request, ReadPortfolioCash $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::position());
        }
        try {
            $view = $read($accountId, PortfolioQueryParameters::string($request, 'from', 'invalid_cash_query'), PortfolioQueryParameters::string($request, 'to', 'invalid_cash_query'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::position());
        }

        return $this->envelope->json(PortfolioCashRepresentation::of($view));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-positions/{instrumentId}/lots', name: 'api_v1_portfolio_position_lots', methods: ['GET'])]
    public function lots(string $accountId, string $instrumentId, Request $request, ReadPortfolioLots $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::position());
        }
        if (!Identifier::isValid($instrumentId)) {
            return $this->problems->respond(new PortfolioInstrumentNotFound(), PortfolioProblems::position());
        }
        try {
            $view = $read($accountId, $instrumentId, PortfolioQueryParameters::string($request, 'asOf', 'invalid_position_query'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::position());
        }

        return $this->envelope->json([
            'asOf' => $view->asOf,
            'instrument' => $view->instrument,
            'openLots' => $view->openLots,
            'movements' => $view->movements,
            'fifoCostBasis' => $view->fifoCostBasis,
        ]);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations/order', name: 'api_v1_portfolio_operations_order', methods: ['PUT'])]
    public function reorder(string $accountId, Request $request, ReorderPortfolioOperations $reorder): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::position());
        }
        // 10 000 UUIDs plus JSON structure, bounded with room for insignificant whitespace.
        $body = $this->envelope->body($request, BodyLimit::Batch, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, ['tradeDate', 'operationIds']);
            $tradeDate = $payload->raw('tradeDate');
            $operationIds = $payload->raw('operationIds');
            if (!is_string($tradeDate) || !is_array($operationIds) || !array_is_list($operationIds)) {
                throw new InvalidPositionQuery('The operation order payload is invalid.');
            }
            $ids = [];
            foreach ($operationIds as $id) {
                if (!is_string($id)) {
                    throw new InvalidPositionQuery('Operation identifiers are strings.');
                }
                $ids[] = $id;
            }
            $view = $reorder($accountId, $tradeDate, $ids);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::position());
        }

        return $this->envelope->json(['tradeDate' => $view->tradeDate, 'operationIds' => $view->operationIds]);
    }
}
