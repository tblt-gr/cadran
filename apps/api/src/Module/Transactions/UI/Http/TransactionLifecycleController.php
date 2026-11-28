<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\DuplicateTransaction;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\Reconciliation\ReconcileTransaction;
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
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions/{id}/void', name: 'api_v1_transactions_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidTransaction $voidTransaction): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['version']);
            $transaction = $voidTransaction($id, $payload->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::void());
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/reconcile', name: 'api_v1_transactions_reconcile', methods: ['POST'])]
    public function reconcile(string $id, Request $request, ReconcileTransaction $reconcileTransaction): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['version', 'matchedTransactionId']);
            $transaction = $reconcileTransaction(
                $id,
                $payload->integer('version'),
                $payload->nullableUuid('matchedTransactionId'),
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::reconcile());
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/duplicate', name: 'api_v1_transactions_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, DuplicateTransaction $duplicateTransaction): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        $hasBody = '' !== $request->getContent();
        $body = [];
        if ($hasBody) {
            $body = $this->envelope->readBody($request);
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
                    JsonPayload::of($body, []);
                    $transaction = $duplicateTransaction($id);

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::duplicate());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }
}
