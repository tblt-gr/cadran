<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\BulkEditResult;
use App\Module\Transactions\Application\BulkEditTransactions;
use App\Module\Transactions\Application\BulkPreview;
use App\Module\Transactions\Application\BulkPreviewRequired;
use App\Module\Transactions\Application\BulkPreviewStale;
use App\Module\Transactions\Application\BulkScopeChanged;
use App\Module\Transactions\Application\BulkScopeTooLarge;
use App\Module\Transactions\Application\BulkTargetAccountNotFound;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\PreviewBulkEdit;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Editing the fields of many transactions at once. */
final readonly class TransactionBulkController
{
    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
        private BulkEditPayload $payload,
    ) {
    }

    #[Route('/api/v1/transactions/bulk-edit', name: 'api_v1_transactions_bulk_edit', methods: ['POST'])]
    public function edit(Request $request, BulkEditTransactions $bulkEdit, PreviewBulkEdit $previewBulk): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $input = $this->payload->parse($body);
            if ($input->preview) {
                // Writes nothing, so it needs no idempotency key and ignores one if sent.
                return $this->envelope->json(self::previewRepresentation($previewBulk($input)));
            }
            $result = $this->idempotentExecution->execute(
                'transaction.bulk_edit',
                $this->envelope->idempotency($request, true),
                static function () use ($bulkEdit, $input): IdempotentResponse {
                    $outcome = $bulkEdit($input);

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
                ['limit' => BulkEditTransactions::MAX_TRANSACTIONS],
                '/problems/transactions.bulk-scope-too-large',
            );
        } catch (BulkScopeChanged $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_CONFLICT, 'api.problem.bulk_scope_changed',
                ['actualCount' => $exception->actualCount], '/problems/transactions.bulk-scope-changed',
            );
        } catch (BulkPreviewRequired) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.bulk_preview_required', '/problems/transactions.bulk-preview-required');
        } catch (BulkPreviewStale) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.bulk_preview_stale', '/problems/transactions.bulk-preview-stale');
        } catch (BulkTargetAccountNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.bulk_target_account_not_found');
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
    private static function representation(BulkEditResult $result): array
    {
        $skipped = [];
        foreach ($result->skipped as $id => $reason) {
            $skipped[] = ['id' => $id, 'reason' => $reason->value];
        }

        return [
            'requestedCount' => $result->requested,
            'changed' => $result->changed,
            'skipped' => $skipped,
            'fieldsChanged' => $result->fieldsChanged,
            'impactedAccountIds' => $result->accountIds,
        ];
    }

    /** @return array<string, mixed> */
    private static function previewRepresentation(BulkPreview $preview): array
    {
        $skipped = [];
        foreach ($preview->skipped as $id => $reason) {
            $skipped[] = ['id' => $id, 'reason' => $reason->value];
        }

        return [
            'eligibleCount' => $preview->eligible,
            'skipped' => $skipped,
            'impacts' => array_map(static fn ($impact): array => [
                'accountId' => $impact->accountId,
                'label' => $impact->label,
                'assetCode' => $impact->assetCode,
                'delta' => $impact->delta,
                'from' => $impact->from,
                'to' => $impact->to,
            ], $preview->impacts),
            'previewToken' => $preview->token,
        ];
    }
}
