<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\ReadRefundable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Reading what remains refundable on a transaction, and creating a refund against it. */
final readonly class TransactionRefundController
{
    private const array REFUND_FIELDS = ['accountId', 'amount', 'bookedOn', 'rawLabel', 'counterparty', 'note', 'splits'];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions/{id}/refundable', name: 'api_v1_transactions_refundable', methods: ['GET'])]
    public function refundable(string $id, ReadRefundable $readRefundable): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        try {
            $view = $readRefundable($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::refundable());
        }

        return $this->envelope->json(RefundRepresentation::refundable($view));
    }

    #[Route('/api/v1/transactions/{id}/refunds', name: 'api_v1_transactions_create_refund', methods: ['POST'])]
    public function createRefund(string $id, Request $request, CreateRefund $createRefund): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $body += ['splits' => null];
            $payload = JsonPayload::of($body, self::REFUND_FIELDS);
            $input = new CreateRefundInput(
                $payload->uuid('accountId'), $payload->amount('amount'), $payload->string('bookedOn'),
                $payload->string('rawLabel'), $payload->nullableString('counterparty'), $payload->nullableString('note'),
                TransactionPayload::nullableSplitRows($payload, 'splits'),
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
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::createRefund());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }
}
