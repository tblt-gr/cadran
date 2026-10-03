<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;

/**
 * Writes, or supersedes, the one active CALCULATED snapshot of an account and day: the total a portfolio
 * valuation publishes to net worth. It runs inside the valuation's own transaction, with the account row
 * already locked, so it opens none and takes no lock.
 */
final readonly class PublishCalculatedAccountValuation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private UuidGenerator $uuidGenerator,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $date, AssetAmount $total): void
    {
        $account = $this->accounts->find($workspace, $accountId) ?? throw new AccountNotFound('No account carries this identifier in this workspace.');
        if (!$total->asset->equals($account->assetCode)) {
            throw new InvalidAccountBalanceInput('A published valuation is in the account unit.');
        }
        $actorId = $this->caller->resolveContext()->actorId;
        // The workspace day may already be tomorrow in UTC: a snapshot is never dated after its own recording.
        $now = max($this->clock->now(), $date);
        $snapshot = new AccountBalanceSnapshot(
            id: $this->uuidGenerator->generate(),
            workspace: $workspace,
            accountId: $accountId,
            asOf: $date,
            amount: $total,
            source: BalanceSnapshotSource::CALCULATED,
            reconciliationStatus: ReconciliationStatus::RECONCILED,
            comment: null,
            version: 1,
            recordedAt: $now,
            recordedBy: $actorId,
        );

        $current = $this->snapshots->findActive($workspace, $accountId, $date, BalanceSnapshotSource::CALCULATED);
        if (null !== $current) {
            $superseded = $current->supersededBy($snapshot);
            if (!$this->snapshots->update($superseded, $current->version)) {
                throw new StaleAccountVersion('The published valuation has been modified by another request.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $workspace, $actorId, AccountBalanceAuditEvents::SUPERSEDED, AccountBalanceAuditEvents::ENTITY, $superseded->id,
                AuditDiff::change(AccountBalanceAuditFingerprint::of($current), AccountBalanceAuditFingerprint::of($superseded)),
            ));
        }
        $this->snapshots->add($snapshot);
        ($this->recordAuditEvent)(new AuditEventRecord(
            $workspace, $actorId, AccountBalanceAuditEvents::RECORDED, AccountBalanceAuditEvents::ENTITY, $snapshot->id,
            AuditDiff::creation(AccountBalanceAuditFingerprint::of($snapshot)),
        ));
    }
}
