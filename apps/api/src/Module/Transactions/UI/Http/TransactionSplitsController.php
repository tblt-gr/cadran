<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ReplaceTransactionSplits;
use App\Module\Transactions\Application\ReplaceTransactionSplitsInput;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionBelongsToRefund;
use App\Module\Transactions\Application\TransactionBelongsToTransfer;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionHasRefunds;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Replacing a transaction's splits: the one write specific to that sub-resource. */
final readonly class TransactionSplitsController
{
    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/transactions/{id}/splits', name: 'api_v1_transactions_replace_splits', methods: ['PUT'])]
    public function replaceSplits(string $id, Request $request, ReplaceTransactionSplits $replaceTransactionSplits): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version', 'splits']);
            $input = new ReplaceTransactionSplitsInput(
                splits: $payload->splitRows('splits'),
                version: $payload->integer('version'),
            );
            $transaction = $replaceTransactionSplits($id, $input);
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
}
