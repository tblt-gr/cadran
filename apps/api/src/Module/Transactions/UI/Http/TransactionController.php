<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\ReadTransaction;
use App\Module\Transactions\Application\UpdateTransaction;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Module\Transactions\Domain\TransactionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The transaction resource's CRUD surface: creation, reading and full-field
 * update. Listing has its own bounded query contract in
 * `TransactionSearchController`; splitting, refunds and the lifecycle
 * operations (void, reconcile, duplicate) live in their own sibling
 * controllers under this same namespace. Every route, path and OpenAPI
 * operation stays exactly as declared regardless of which class serves it.
 */
final readonly class TransactionController
{
    private const array CREATE_FIELDS = [
        'accountId', 'amount', 'nature', 'state', 'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel',
        'counterparty', 'note', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference', 'categoryId', 'splits', 'source',
        'sourceRef', 'reconcile',
    ];
    private const array UPDATE_FIELDS = [
        'accountId', 'amount', 'nature', 'state', 'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel',
        'counterparty', 'note', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference', 'categoryId', 'splits', 'version',
        'userLabel',
    ];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_create', methods: ['POST'])]
    public function create(Request $request, CreateTransaction $createTransaction): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            // Optional on the wire, always present here: the exact-field check
            // below refuses anything it was not told to expect.
            $body += ['source' => TransactionSource::MANUAL->value, 'sourceRef' => null, 'reconcile' => false];
            $payload = JsonPayload::of($body, self::CREATE_FIELDS);
            $source = $payload->string('source');
            if (TransactionSource::RECURRENCE->value === $source) {
                // Only a recurrence creates this source, through its own use case.
                throw new MalformedPayload('A client cannot create a recurrence-sourced transaction.');
            }
            $result = $this->idempotentExecution->execute(
                'transaction.create',
                $this->envelope->idempotency($request, in_array($source, [TransactionSource::IMPORT->value, TransactionSource::PROVIDER->value], true)),
                function () use ($createTransaction, $payload): IdempotentResponse {
                    $transaction = $createTransaction(self::createInput($payload));

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::create());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_read', methods: ['GET'])]
    public function read(string $id, ReadTransaction $readTransaction): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        try {
            $transaction = $readTransaction($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::read());
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateTransaction $updateTransaction): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, self::UPDATE_FIELDS);
            $transaction = $updateTransaction($id, self::updateInput($payload));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::update());
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    private static function createInput(JsonPayload $payload): CreateTransactionInput
    {
        return new CreateTransactionInput(
            accountId: $payload->uuid('accountId'),
            amount: $payload->amount('amount'),
            nature: $payload->string('nature'),
            state: $payload->string('state'),
            bookedOn: $payload->string('bookedOn'),
            valueOn: $payload->nullableString('valueOn'),
            authorizedOn: $payload->nullableString('authorizedOn'),
            rawLabel: $payload->string('rawLabel'),
            counterparty: $payload->nullableString('counterparty'),
            note: $payload->nullableString('note'),
            paymentMethod: $payload->nullableString('paymentMethod'),
            mcc: $payload->nullableString('mcc'),
            maskedCard: $payload->nullableString('maskedCard'),
            bankReference: $payload->nullableString('bankReference'),
            categoryId: $payload->nullableUuid('categoryId'),
            splits: TransactionPayload::nullableSplitRows($payload, 'splits'),
            source: $payload->string('source'),
            sourceRef: $payload->nullableString('sourceRef'),
            reconcile: $payload->boolean('reconcile'),
        );
    }

    private static function updateInput(JsonPayload $payload): UpdateTransactionInput
    {
        return new UpdateTransactionInput(
            accountId: $payload->uuid('accountId'),
            amount: $payload->amount('amount'),
            nature: $payload->string('nature'),
            state: $payload->string('state'),
            bookedOn: $payload->string('bookedOn'),
            valueOn: $payload->nullableString('valueOn'),
            authorizedOn: $payload->nullableString('authorizedOn'),
            rawLabel: $payload->string('rawLabel'),
            counterparty: $payload->nullableString('counterparty'),
            note: $payload->nullableString('note'),
            paymentMethod: $payload->nullableString('paymentMethod'),
            mcc: $payload->nullableString('mcc'),
            maskedCard: $payload->nullableString('maskedCard'),
            bankReference: $payload->nullableString('bankReference'),
            categoryId: $payload->nullableUuid('categoryId'),
            splits: TransactionPayload::nullableSplitRows($payload, 'splits'),
            version: $payload->integer('version'),
            userLabel: $payload->nullableString('userLabel'),
        );
    }
}
