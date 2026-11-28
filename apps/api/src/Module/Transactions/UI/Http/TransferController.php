<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\ReadTransfer;
use App\Module\Transactions\Application\UpdateTransfer;
use App\Module\Transactions\Application\UpdateTransferInput;
use App\Module\Transactions\Application\VoidTransfer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransferController
{
    private const array CREATE_FIELDS = [
        'sourceAccountId', 'targetAccountId', 'sourceAmount', 'targetAmount',
        'state', 'bookedOn', 'valueOn', 'label', 'note', 'fee',
    ];
    private const array UPDATE_FIELDS = [
        'sourceAccountId', 'targetAccountId', 'state', 'bookedOn', 'valueOn', 'label', 'note', 'fee', 'version',
    ];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transfers', name: 'api_v1_transfers_create', methods: ['POST'])]
    public function create(Request $request, CreateTransfer $createTransfer): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, self::CREATE_FIELDS);
            $input = new CreateTransferInput(
                sourceAccountId: $payload->uuid('sourceAccountId'),
                targetAccountId: $payload->uuid('targetAccountId'),
                sourceAmount: $payload->amount('sourceAmount'),
                targetAmount: null === $payload->raw('targetAmount') ? null : $payload->amount('targetAmount'),
                state: $payload->string('state'),
                bookedOn: $payload->string('bookedOn'),
                valueOn: $payload->nullableString('valueOn'),
                label: $payload->string('label'),
                note: $payload->nullableString('note'),
                fee: null === $payload->raw('fee') ? null : $payload->amount('fee'),
            );
            $result = $this->idempotentExecution->execute(
                'transfer.create',
                $this->envelope->idempotency($request, false),
                function () use ($createTransfer, $input): IdempotentResponse {
                    $transfer = $createTransfer($input);

                    return new IdempotentResponse(TransferRepresentation::one($transfer), Response::HTTP_CREATED, $transfer->id, [$transfer->source->bookedOn]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransferProblems::rules());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/transfers/{id}', name: 'api_v1_transfers_read', methods: ['GET'])]
    public function read(string $id, ReadTransfer $readTransfer): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transfer_not_found');
        }

        try {
            $transfer = $readTransfer($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransferProblems::rules());
        }

        return $this->envelope->json(TransferRepresentation::one($transfer));
    }

    #[Route('/api/v1/transfers/{id}', name: 'api_v1_transfers_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateTransfer $updateTransfer): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transfer_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, self::UPDATE_FIELDS);
            $transfer = $updateTransfer($id, new UpdateTransferInput(
                sourceAccountId: $payload->uuid('sourceAccountId'),
                targetAccountId: $payload->uuid('targetAccountId'),
                state: $payload->string('state'),
                bookedOn: $payload->string('bookedOn'),
                valueOn: $payload->nullableString('valueOn'),
                label: $payload->string('label'),
                note: $payload->nullableString('note'),
                fee: null === $payload->raw('fee') ? null : $payload->amount('fee'),
                version: $payload->integer('version'),
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransferProblems::rules());
        }

        return $this->envelope->json(TransferRepresentation::one($transfer));
    }

    #[Route('/api/v1/transfers/{id}/void', name: 'api_v1_transfers_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidTransfer $voidTransfer): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transfer_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['version']);
            $transfer = $voidTransfer($id, $payload->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransferProblems::rules());
        }

        return $this->envelope->json(TransferRepresentation::one($transfer));
    }
}
