<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\TransactionAuditEvents;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationResolution;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Accepts an observed closing balance once it has been compared to the
 * movements of its period.
 *
 * Nothing is adjusted silently: a non-zero discrepancy needs an explicit
 * OVERRIDE (accepted as it stands, no ledger write) or ADJUST (one ADJUSTMENT
 * movement equal to the discrepancy). The account row is locked and the
 * snapshot re-read inside the transaction, so two concurrent requests cannot
 * both adjust: the loser finds the snapshot already reconciled.
 */
final readonly class ReconcileAccountBalance
{
    public const string ADJUSTMENT_LABEL = 'Balance adjustment';

    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private TransactionRepository $transactions,
        private AccountReconciliationCalculator $calculator,
        private CreateTransaction $createTransaction,
        private CategorizationWriteLock $categorizationWriteLock,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(string $accountId, ReconcileAccountBalanceInput $input): AccountReconciliationView
    {
        $context = $this->caller->resolveContext();
        $resolution = ReconciliationResolution::tryFrom($input->resolution)
            ?? throw new InvalidAccountReconciliation('The resolution must be MATCH, OVERRIDE or ADJUST.');

        return $this->transactionBoundary->transactional(function () use ($accountId, $input, $resolution, $context): AccountReconciliationView {
            $workspace = $context->workspace;
            if (ReconciliationResolution::ADJUST === $resolution) {
                // Same order as CreateTransaction (categorisation lock, then
                // account row) so a plain entry and an adjustment never deadlock.
                $this->categorizationWriteLock->acquire($workspace);
            }
            $account = $this->accounts->findForUpdate($workspace, $accountId);
            $snapshot = null === $account ? null : $this->snapshots->find($workspace, $account->id, $input->snapshotId);
            if (null === $account || null === $snapshot) {
                throw new AccountReconciliationNotFound('No such snapshot on this account in this workspace.');
            }
            if (ReconciliationStatus::RECONCILED === $snapshot->reconciliationStatus) {
                throw new AccountReconciliationConflict('This snapshot is already reconciled.');
            }
            if ($snapshot->version !== $input->snapshotVersion) {
                throw new StaleAccountReconciliation('The snapshot has been modified by another request.');
            }
            if (null !== $account->archivedAt) {
                throw new AccountReconciliationConflict('An archived account is read-only.');
            }

            $start = $this->calculator->periodStart($input->periodStart, $snapshot);
            $figures = $this->calculator->compare($workspace, $snapshot, $start);
            $comparison = $figures->comparison;
            if (null === $comparison->discrepancy) {
                throw new InvalidAccountReconciliation('The discrepancy cannot be calculated, so nothing can be reconciled.');
            }
            if (!in_array($resolution, $comparison->availableResolutions(), true)) {
                throw new InvalidAccountReconciliation('This resolution does not fit the discrepancy.');
            }
            $pendingCount = $this->transactions->countPendingInPeriod($workspace, $account->id, $figures->windowStart, $snapshot->asOf);

            $adjustmentId = null;
            if (ReconciliationResolution::ADJUST === $resolution) {
                $adjustmentId = ($this->createTransaction)(new CreateTransactionInput(
                    accountId: $account->id,
                    amount: ['value' => $comparison->discrepancy->toString(), 'assetCode' => $comparison->asset->toString()],
                    nature: TransactionNature::ADJUSTMENT->value,
                    state: TransactionState::BOOKED->value,
                    bookedOn: $snapshot->asOf->format('Y-m-d'),
                    valueOn: null, authorizedOn: null,
                    rawLabel: self::ADJUSTMENT_LABEL,
                    counterparty: null, note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
                    categoryId: null, splits: null,
                    source: TransactionSource::MANUAL->value,
                    automation: false,
                ))->id;
            }

            $reconciled = $snapshot->markReconciled();
            if (!$this->snapshots->update($reconciled, $snapshot->version)) {
                throw new StaleAccountReconciliation('The snapshot has been modified by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $workspace,
                actorId: $context->actorId,
                eventType: ReconciliationResolution::OVERRIDE === $resolution
                    ? TransactionAuditEvents::RECONCILIATION_OVERRIDDEN
                    : TransactionAuditEvents::ACCOUNT_RECONCILED,
                entityType: 'account_balance_snapshot',
                entityId: $snapshot->id,
                diff: AuditDiff::change(
                    AccountReconciliationAuditFingerprint::before($snapshot),
                    AccountReconciliationAuditFingerprint::after($reconciled, $resolution, $figures->windowStart, $pendingCount, $comparison->isBalanced(), $adjustmentId),
                ),
            ));

            return $this->calculator->view($workspace, $reconciled, $this->calculator->compare($workspace, $reconciled, $start));
        });
    }
}
