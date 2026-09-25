<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\DuplicateTransaction;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\Reconciliation\ReconcileTransaction;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionBelongsToRefund;
use App\Module\Transactions\Application\TransactionBelongsToTransfer;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionHasRefunds;
use App\Module\Transactions\Application\TransactionNotFound;
use App\Module\Transactions\Application\VoidTransaction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The operations that change a transaction's lifecycle state without
 * replacing its content: voiding it, reconciling it against a matched
 * import row, and duplicating it into a new one.
 */
final readonly class TransactionLifecycleController
{
    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions/{id}/void', name: 'api_v1_transactions_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidTransaction $voidTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version']);
            $transaction = $voidTransaction($id, $payload->integer('version'));
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToTransfer $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_transfer',
                ['transferId' => $exception->transferId], '/problems/transaction-belongs-to-transfer',
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

    #[Route('/api/v1/transactions/{id}/reconcile', name: 'api_v1_transactions_reconcile', methods: ['POST'])]
    public function reconcile(string $id, Request $request, ReconcileTransaction $reconcileTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version', 'matchedTransactionId']);
            $transaction = $reconcileTransaction(
                $id,
                $payload->integer('version'),
                $payload->nullableIdentifier('matchedTransactionId'),
            );
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
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

    #[Route('/api/v1/transactions/{id}/duplicate', name: 'api_v1_transactions_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, DuplicateTransaction $duplicateTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        $hasBody = '' !== $request->getContent();
        $body = [];
        if ($hasBody) {
            $body = $this->envelope->body($request);
            if ($body instanceof Response) {
                return $body;
            }
        }
        try {
            $result = $this->idempotentExecution->execute(
                'transaction.duplicate',
                $this->envelope->idempotency($request, false, true),
                function () use ($duplicateTransaction, $id, $body, $hasBody, $request): IdempotentResponse {
                    if ($hasBody && !$this->envelope->hasJsonObjectBody($request)) {
                        throw new InvalidTransactionInput();
                    }
                    TransactionPayload::of($body, []);
                    $transaction = $duplicateTransaction($id);

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToRefund $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_refund',
                ['originalId' => $exception->originalTransactionId], '/problems/transaction.belongs_to_refund',
            );
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }
}
