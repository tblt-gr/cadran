<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidRefundRule;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ReadRefundable;
use App\Module\Transactions\Application\RefundConflict;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Reading what remains refundable on a transaction, and creating a refund against it. */
final readonly class TransactionRefundController
{
    private const array REFUND_FIELDS = ['accountId', 'amount', 'bookedOn', 'rawLabel', 'counterparty', 'note', 'splits'];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions/{id}/refundable', name: 'api_v1_transactions_refundable', methods: ['GET'])]
    public function refundable(string $id, ReadRefundable $readRefundable): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        try {
            $view = $readRefundable($id);
        } catch (InvalidRefundRule $exception) {
            return $this->envelope->invalidRefundRuleProblem($exception);
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(RefundRepresentation::refundable($view));
    }

    #[Route('/api/v1/transactions/{id}/refunds', name: 'api_v1_transactions_create_refund', methods: ['POST'])]
    public function createRefund(string $id, Request $request, CreateRefund $createRefund): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $body += ['splits' => null];
            $payload = TransactionPayload::of($body, self::REFUND_FIELDS);
            $input = new CreateRefundInput(
                $payload->identifier('accountId'), $payload->amount('amount'), $payload->string('bookedOn'),
                $payload->string('rawLabel'), $payload->nullableString('counterparty'), $payload->nullableString('note'),
                $payload->nullableSplitRows('splits'),
            );
            $result = $this->idempotentExecution->execute(
                'refund.create',
                $this->envelope->idempotency($request, false),
                function () use ($createRefund, $id, $input): IdempotentResponse {
                    $created = $createRefund($id, $input);
                    $refund = $created->refund;

                    return new IdempotentResponse(
                        TransactionRepresentation::one($refund), Response::HTTP_CREATED, $refund->id,
                        [$refund->bookedOn, $created->originalBookedOn],
                    );
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidRefundRule $exception) {
            return $this->envelope->invalidRefundRuleProblem($exception);
        } catch (RefundConflict $exception) {
            return $this->envelope->refundConflictProblem($exception);
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
}
