<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Stops one observed balance from answering, without deleting the row.
 *
 * The trail keeps the figure. Later days fall back to the previous active
 * snapshot, then to the movements booked after it.
 */
final readonly class WithdrawAccountBalance
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
        private AssertPeriodOpen $assertPeriodOpen,
    ) {
    }

    public function __invoke(string $accountId, string $snapshotId, int $expectedVersion): AccountBalanceSnapshot
    {
        if ($expectedVersion < 1) {
            throw new InvalidAccountBalanceInput('The snapshot version must be positive.');
        }

        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $snapshotId, $expectedVersion, $context): AccountBalanceSnapshot {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound('No account carries this identifier in this workspace.');
            }
            if (null !== $account->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            $current = $this->snapshots->find($context->workspace, $account->id, $snapshotId);
            if (null === $current) {
                throw new AccountBalanceNotFound('No balance snapshot carries this identifier on this account.');
            }
            if (!$current->isActive()) {
                throw new AccountBalanceConflict('This snapshot has already been withdrawn.');
            }
            if (BalanceSnapshotSource::CALCULATED === $current->source) {
                throw new AccountBalanceConflict('A calculated snapshot is withdrawn with its portfolio valuation.');
            }
            if ($current->version !== $expectedVersion) {
                throw new StaleAccountVersion('The snapshot has been modified by another request.');
            }

            ($this->assertPeriodOpen)($context->workspace, $current->asOf);
            $withdrawn = $current->withdrawn(max($this->clock->now(), $current->recordedAt));
            if (!$this->snapshots->update($withdrawn, $expectedVersion)) {
                throw new StaleAccountVersion('The snapshot has been modified by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountBalanceAuditEvents::SUPERSEDED,
                entityType: AccountBalanceAuditEvents::ENTITY,
                entityId: $withdrawn->id,
                diff: AuditDiff::change(
                    AccountBalanceAuditFingerprint::of($current),
                    AccountBalanceAuditFingerprint::of($withdrawn),
                ),
            ));

            return $withdrawn;
        });
    }
}
