<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ReadTransaction;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionBelongsToRefund;
use App\Module\Transactions\Application\TransactionBelongsToTransfer;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionHasRefunds;
use App\Module\Transactions\Application\TransactionNotFound;
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
    ];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_create', methods: ['POST'])]
    public function create(Request $request, CreateTransaction $createTransaction): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            // Optional on the wire, always present here: the exact-field check
            // below refuses anything it was not told to expect.
            $body += ['source' => TransactionSource::MANUAL->value, 'sourceRef' => null, 'reconcile' => false];
            $payload = TransactionPayload::of($body, self::CREATE_FIELDS);
            $source = $payload->string('source');
            $result = $this->idempotentExecution->execute(
                'transaction.create',
                $this->envelope->idempotency($request, in_array($source, [TransactionSource::IMPORT->value, TransactionSource::PROVIDER->value], true)),
                function () use ($createTransaction, $payload): IdempotentResponse {
                    $transaction = $createTransaction(self::createInput($payload));

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_read', methods: ['GET'])]
    public function read(string $id, ReadTransaction $readTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        try {
            $transaction = $readTransaction($id);
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateTransaction $updateTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, self::UPDATE_FIELDS);
            $transaction = $updateTransaction($id, self::updateInput($payload));
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToTransfer $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_transfer',
                ['transferId' => $exception->transferId], '/problems/transaction-belongs-to-transfer',
            );
        } catch (TransactionBelongsToRefund $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_refund',
                ['originalId' => $exception->originalTransactionId], '/problems/transaction.belongs_to_refund',
            );
        } catch (TransactionHasRefunds) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_has_refunds', '/problems/transaction.has_refunds');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (StaleTransactionVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    private static function createInput(TransactionPayload $payload): CreateTransactionInput
    {
        return new CreateTransactionInput(
            accountId: $payload->identifier('accountId'),
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
            categoryId: $payload->nullableIdentifier('categoryId'),
            splits: $payload->nullableSplitRows('splits'),
            source: $payload->string('source'),
            sourceRef: $payload->nullableString('sourceRef'),
            reconcile: $payload->boolean('reconcile'),
        );
    }

    private static function updateInput(TransactionPayload $payload): UpdateTransactionInput
    {
        return new UpdateTransactionInput(
            accountId: $payload->identifier('accountId'),
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
            categoryId: $payload->nullableIdentifier('categoryId'),
            splits: $payload->nullableSplitRows('splits'),
            version: $payload->integer('version'),
        );
    }
}
