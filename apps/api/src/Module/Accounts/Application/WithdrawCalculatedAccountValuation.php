<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;

/** Supersedes the active CALCULATED snapshot of an account and day without a replacement; a no-op when none is published. */
final readonly class WithdrawCalculatedAccountValuation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountBalanceSnapshotRepository $snapshots,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    /** @return bool whether a published figure was withdrawn */
    public function __invoke(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $date): bool
    {
        $current = $this->snapshots->findActive($workspace, $accountId, $date, BalanceSnapshotSource::CALCULATED);
        if (null === $current) {
            return false;
        }
        $withdrawn = $current->withdrawn(max($this->clock->now(), $current->recordedAt));
        if (!$this->snapshots->update($withdrawn, $current->version)) {
            throw new StaleAccountVersion('The published valuation has been modified by another request.');
        }
        ($this->recordAuditEvent)(new AuditEventRecord(
            $workspace, $this->caller->resolveContext()->actorId, AccountBalanceAuditEvents::SUPERSEDED, AccountBalanceAuditEvents::ENTITY, $withdrawn->id,
            AuditDiff::change(AccountBalanceAuditFingerprint::of($current), AccountBalanceAuditFingerprint::of($withdrawn)),
        ));

        return true;
    }
}
