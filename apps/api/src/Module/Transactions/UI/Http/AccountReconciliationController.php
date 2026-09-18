<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationConflict;
use App\Module\Transactions\Application\Reconciliation\AccountReconciliationNotFound;
use App\Module\Transactions\Application\Reconciliation\InvalidAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\ReadAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalance;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalanceInput;
use App\Module\Transactions\Application\Reconciliation\StaleAccountReconciliation;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Compares an observed account balance to its period's movements and resolves the gap. */
final readonly class AccountReconciliationController
{
    private const array RECONCILE_FIELDS = ['snapshotId', 'snapshotVersion', 'periodStart', 'resolution'];
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/reconciliation', name: 'api_v1_account_reconciliation_read', methods: ['GET'])]
    public function read(string $accountId, Request $request, ReadAccountReconciliation $readAccountReconciliation): Response
    {
        if (!$this->envelope->isIdentifier($accountId)) {
            return $this->notFound();
        }
        $snapshotId = $request->query->get('snapshotId');
        $periodStart = $request->query->get('periodStart');
        if (!is_string($snapshotId) || !$this->envelope->isIdentifier($snapshotId)
            || !is_string($periodStart) || 1 !== preg_match(self::DATE_PATTERN, $periodStart)
            || [] !== array_diff(array_keys($request->query->all()), ['snapshotId', 'periodStart'])) {
            return $this->invalid();
        }

        try {
            $view = $readAccountReconciliation($accountId, $snapshotId, $periodStart);
        } catch (AccountReconciliationNotFound) {
            return $this->notFound();
        } catch (InvalidAccountReconciliation) {
            return $this->invalid();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(AccountReconciliationRepresentation::one($view));
    }

    #[Route('/api/v1/accounts/{accountId}/reconciliation', name: 'api_v1_account_reconciliation_resolve', methods: ['POST'])]
    public function resolve(string $accountId, Request $request, ReconcileAccountBalance $reconcileAccountBalance): Response
    {
        if (!$this->envelope->isIdentifier($accountId)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, self::RECONCILE_FIELDS);
            $input = new ReconcileAccountBalanceInput(
                $payload->identifier('snapshotId'),
                $payload->integer('snapshotVersion'),
                $payload->string('periodStart'),
                $payload->string('resolution'),
            );
            $result = $this->idempotentExecution->execute(
                'account.reconcile',
                $this->envelope->idempotency($request, false),
                static function () use ($reconcileAccountBalance, $accountId, $input): IdempotentResponse {
                    $view = $reconcileAccountBalance($accountId, $input);

                    return new IdempotentResponse(AccountReconciliationRepresentation::one($view), Response::HTTP_OK, $view->snapshotId);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidAccountReconciliation|InvalidTransactionInput|\UnexpectedValueException) {
            return $this->invalid();
        } catch (AccountReconciliationNotFound|TransactionNotFound) {
            return $this->notFound();
        } catch (StaleAccountReconciliation) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_stale', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (AccountReconciliationConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.account_reconciliation_adjustment_refused', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    private function notFound(): Response
    {
        return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_reconciliation_not_found');
    }

    private function invalid(): Response
    {
        return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_reconciliation_invalid');
    }

    private function forbidden(): Response
    {
        return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_reconciliation_forbidden');
    }
}
