<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\QueryParameters;
use App\Module\Portfolio\Application\CreateInstrument;
use App\Module\Portfolio\Application\DeactivateInstrument;
use App\Module\Portfolio\Application\InstrumentNotFound;
use App\Module\Portfolio\Application\InvalidInstrumentInput;
use App\Module\Portfolio\Application\ListInstruments;
use App\Module\Portfolio\Application\ReactivateInstrument;
use App\Module\Portfolio\Application\ReadInstrument;
use App\Module\Portfolio\Application\ReadInstrumentHolding;
use App\Module\Portfolio\Application\UpdateInstrument;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use App\Module\Transactions\UI\Http\TransactionProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class InstrumentController
{
    public function __construct(
        private JsonRequestEnvelope $envelope,
        private ProblemMapper $problems,
        private TransactionHttpEnvelope $idempotency,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/instruments', name: 'api_v1_instruments_list', methods: ['GET'])]
    public function list(Request $request, ListInstruments $list): Response
    {
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        if (!QueryParameters::isUnsignedIntegerOrEmpty($page) || !QueryParameters::isUnsignedIntegerOrEmpty($pageSize)) {
            return $this->problems->respond(new InvalidInstrumentInput('The pagination is invalid.', 'invalid_instrument_query'), PortfolioProblems::instrumentList());
        }
        try {
            $result = $list(
                $request->query->has('q') ? $request->query->getString('q') : null,
                $request->query->getString('status'),
                QueryParameters::integerOrNull($page),
                QueryParameters::integerOrNull($pageSize),
                $request->query->has('currencyCode') ? $request->query->getString('currencyCode') : null,
                $request->query->has('instrumentType') ? $request->query->getString('instrumentType') : null,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::instrumentList());
        }

        return $this->envelope->json(InstrumentRepresentation::page($result));
    }

    #[Route('/api/v1/instruments', name: 'api_v1_instruments_create', methods: ['POST'])]
    public function create(Request $request, CreateInstrument $create): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.instrument_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = InstrumentPayload::create($body);
            $result = $this->idempotentExecution->execute(
                'instrument.create',
                $this->idempotency->idempotency($request, false),
                static function () use ($create, $input): IdempotentResponse {
                    $instrument = $create($input);

                    return new IdempotentResponse(InstrumentRepresentation::one($instrument), Response::HTTP_CREATED, $instrument->id);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, [...TransactionProblems::idempotency(), ...PortfolioProblems::instrumentWrite()]);
        }

        return $this->idempotency->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/instruments/{id}', name: 'api_v1_instruments_show', methods: ['GET'])]
    public function show(string $id, ReadInstrument $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new InstrumentNotFound(), PortfolioProblems::instrumentRead());
        }
        try {
            $instrument = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::instrumentRead());
        }

        return $this->envelope->json(InstrumentRepresentation::one($instrument));
    }

    #[Route('/api/v1/instruments/{id}/holding', name: 'api_v1_instruments_holding', methods: ['GET'])]
    public function holding(string $id, ReadInstrumentHolding $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new InstrumentNotFound(), PortfolioProblems::instrumentRead());
        }
        try {
            $result = $read($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::instrumentRead());
        }
        $result['instrument'] = InstrumentRepresentation::one($result['instrument']);

        return $this->envelope->json($result);
    }

    #[Route('/api/v1/instruments/{id}', name: 'api_v1_instruments_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateInstrument $update): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new InstrumentNotFound(), PortfolioProblems::instrumentRead());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.instrument_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $instrument = $update($id, InstrumentPayload::update($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::instrumentWrite());
        }

        return $this->envelope->json(InstrumentRepresentation::one($instrument));
    }

    #[Route('/api/v1/instruments/{id}/deactivate', name: 'api_v1_instruments_deactivate', methods: ['POST'])]
    public function deactivate(string $id, Request $request, DeactivateInstrument $deactivate): Response
    {
        return $this->lifecycle($id, $request, $deactivate);
    }

    #[Route('/api/v1/instruments/{id}/reactivate', name: 'api_v1_instruments_reactivate', methods: ['POST'])]
    public function reactivate(string $id, Request $request, ReactivateInstrument $reactivate): Response
    {
        return $this->lifecycle($id, $request, $reactivate);
    }

    /** @param callable(string, int): \App\Module\Portfolio\Application\InstrumentView $change */
    private function lifecycle(string $id, Request $request, callable $change): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->respond(new InstrumentNotFound(), PortfolioProblems::instrumentRead());
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.instrument_payload_too_large', PortfolioProblems::refusal($this->envelope));
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $instrument = $change($id, InstrumentPayload::version($body));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::instrumentWrite());
        }

        return $this->envelope->json(InstrumentRepresentation::one($instrument));
    }
}
