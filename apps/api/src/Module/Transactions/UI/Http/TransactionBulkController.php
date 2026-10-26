<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\BulkCategorizeResult;
use App\Module\Transactions\Application\BulkRecategorizeTransactions;
use App\Module\Transactions\Application\BulkScopeChanged;
use App\Module\Transactions\Application\BulkScopeTooLarge;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Changing the category of many transactions at once. */
final readonly class TransactionBulkController
{
    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
        private BulkCategoryPayload $payload,
    ) {
    }

    #[Route('/api/v1/transactions/bulk-category', name: 'api_v1_transactions_bulk_category', methods: ['POST'])]
    public function category(Request $request, BulkRecategorizeTransactions $bulkRecategorize): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $input = $this->payload->parse($body);
            $result = $this->idempotentExecution->execute(
                'transaction.bulk_category',
                $this->envelope->idempotency($request, true),
                static function () use ($bulkRecategorize, $input): IdempotentResponse {
                    $outcome = $bulkRecategorize($input);

                    return new IdempotentResponse(self::representation($outcome), Response::HTTP_OK, null, $outcome->periodDays);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (BulkScopeTooLarge) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.bulk_scope_too_large',
                ['limit' => BulkRecategorizeTransactions::MAX_TRANSACTIONS],
                '/problems/transactions.bulk-scope-too-large',
            );
        } catch (BulkScopeChanged $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_CONFLICT, 'api.problem.bulk_scope_changed',
                ['actualCount' => $exception->actualCount], '/problems/transactions.bulk-scope-changed',
            );
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found');
        } catch (StaleTransactionVersion|TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /** @return array<string, mixed> */
    private static function representation(BulkCategorizeResult $result): array
    {
        $skipped = [];
        foreach ($result->skipped as $id => $reason) {
            $skipped[] = ['transactionId' => $id, 'reason' => $reason->value];
        }

        return [
            'requestedCount' => $result->requested,
            'changed' => $result->changed,
            'skipped' => $skipped,
            'maximum' => BulkRecategorizeTransactions::MAX_TRANSACTIONS,
        ];
    }
}
