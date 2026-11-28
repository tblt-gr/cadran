<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\Takeover\PreviewPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\ReadPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\TakeoverInput;
use App\Module\Portfolio\Application\Takeover\TakeOverPortfolioPositions;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use App\Module\Transactions\UI\Http\TransactionProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortfolioTakeoverController
{
    public function __construct(
        private JsonRequestEnvelope $envelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $idempotency,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover', name: 'api_v1_portfolio_takeover_show', methods: ['GET'])]
    public function show(string $accountId, ReadPortfolioTakeover $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::takeover());
        }
        try {
            $state = $read($accountId);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::takeover());
        }

        return $this->envelope->json(PortfolioTakeoverRepresentation::state($state));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover/preview', name: 'api_v1_portfolio_takeover_preview', methods: ['POST'])]
    public function preview(string $accountId, Request $request, PreviewPortfolioTakeover $preview, ReadPortfolioTakeover $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::takeover());
        }
        $body = $this->envelope->body($request, BodyLimit::Bulk, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $preview($accountId, $this->input($body, $accountId, $read));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::takeover());
        }

        return $this->envelope->json(PortfolioTakeoverRepresentation::preview($result));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover', name: 'api_v1_portfolio_takeover_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, TakeOverPortfolioPositions $takeOver, ReadPortfolioTakeover $read): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->problems->respond(new PortfolioAccountNotFound(), PortfolioProblems::takeover());
        }
        $body = $this->envelope->body($request, BodyLimit::Bulk, 'api.problem.portfolio_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = $this->input($body, $accountId, $read);
            $result = $this->idempotentExecution->execute(
                'portfolio.takeover.record',
                $this->idempotency->idempotency($request, true),
                static function () use ($takeOver, $accountId, $input): IdempotentResponse {
                    $view = $takeOver($accountId, $input);

                    return new IdempotentResponse(PortfolioTakeoverRepresentation::takeover($view), Response::HTTP_CREATED, $view->takeover->id, [$view->takeover->takenOverOn->format('Y-m-d')]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, [...TransactionProblems::idempotency(), ...PortfolioProblems::takeover()]);
        }

        return $this->idempotency->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    private function input(array $body, string $accountId, ReadPortfolioTakeover $read): TakeoverInput
    {
        try {
            return TakeoverPayload::takeover($body);
        } catch (MalformedPayload $exception) {
            // A foreign or unknown account answers 404 whatever the body looks like.
            $read($accountId);

            throw $exception;
        }
    }
}
