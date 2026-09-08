<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\ConflictingAccountBalanceSnapshot;
use App\Module\Accounts\Domain\InvalidAccountBalanceSnapshot;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a manual observed balance on one account.
 *
 * The account row is locked for the whole operation. Two active snapshots of
 * the same day and source would leave that day with two answers; the lock
 * serialises writers and the database repeats the refusal.
 */
final readonly class RecordAccountBalance
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private AmountInputParser $amounts,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, RecordAccountBalanceInput $input): AccountBalanceSnapshot
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $input, $context): AccountBalanceSnapshot {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound('No account carries this identifier in this workspace.');
            }

            if (null !== $account->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            $snapshot = $this->submitted($account, $input, $context->actorId);
            $current = $this->snapshots->findActive(
                $context->workspace,
                $account->id,
                $snapshot->asOf,
                BalanceSnapshotSource::MANUAL,
            );

            if (null !== $current) {
                if (null === $input->version) {
                    throw new AccountBalanceConflict('An active snapshot already exists for this account, date and source.');
                }

                if ($current->version !== $input->version) {
                    throw new StaleAccountVersion('The snapshot has been modified by another request.');
                }

                $superseded = $current->supersededBy($snapshot);
                if (!$this->snapshots->update($superseded, $input->version)) {
                    throw new StaleAccountVersion('The snapshot has been modified by another request.');
                }

                ($this->recordAuditEvent)(new AuditEventRecord(
                    workspace: $context->workspace,
                    actorId: $context->actorId,
                    eventType: AccountBalanceAuditEvents::SUPERSEDED,
                    entityType: AccountBalanceAuditEvents::ENTITY,
                    entityId: $superseded->id,
                    diff: AuditDiff::change(
                        AccountBalanceAuditFingerprint::of($current),
                        AccountBalanceAuditFingerprint::of($superseded),
                    ),
                ));
            } elseif (null !== $input->version) {
                throw new StaleAccountVersion('The snapshot has been modified by another request.');
            }

            try {
                $this->snapshots->add($snapshot);
            } catch (ConflictingAccountBalanceSnapshot $collision) {
                throw new AccountBalanceConflict($collision->getMessage(), previous: $collision);
            }

            $used = $account->markUsed($this->clock->now());
            if ($used !== $account && !$this->accounts->update($used, $account->version)) {
                throw new StaleAccountVersion('The account has been modified by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountBalanceAuditEvents::RECORDED,
                entityType: AccountBalanceAuditEvents::ENTITY,
                entityId: $snapshot->id,
                diff: AuditDiff::creation(AccountBalanceAuditFingerprint::of($snapshot)),
            ));

            return $snapshot;
        });
    }

    private function submitted(Account $account, RecordAccountBalanceInput $input, string $actorId): AccountBalanceSnapshot
    {
        try {
            $asOf = BusinessDay::fromIsoDate($input->asOf)->date;
        } catch (\Throwable $exception) {
            throw new InvalidAccountBalanceInput('The snapshot date must be an ISO 8601 calendar day.', previous: $exception);
        }

        if ($asOf < $account->openedOn) {
            throw new InvalidAccountBalanceInput('A snapshot cannot predate the account opening.');
        }

        if (null !== $account->closedOn && $asOf > $account->closedOn) {
            throw new InvalidAccountBalanceInput('A snapshot cannot postdate the account closing.');
        }

        $amount = $this->amounts->fromFields(
            $input->amount,
            $input->amountAssetCode,
            '/amount',
            '/amountAssetCode',
        );

        if (!$amount->asset->equals($account->assetCode)) {
            throw new InvalidAccountBalanceInput('A snapshot is recorded in the account unit.');
        }

        $comment = null === $input->comment ? null : trim($input->comment);
        if ('' === $comment) {
            $comment = null;
        }

        try {
            return new AccountBalanceSnapshot(
                id: $this->uuidGenerator->generate(),
                workspace: $account->workspace,
                accountId: $account->id,
                asOf: $asOf,
                amount: $amount,
                source: BalanceSnapshotSource::MANUAL,
                reconciliationStatus: ReconciliationStatus::UNRECONCILED,
                comment: $comment,
                version: 1,
                recordedAt: $this->clock->now(),
                recordedBy: $actorId,
            );
        } catch (InvalidAccountBalanceSnapshot $exception) {
            throw new InvalidAccountBalanceInput($exception->getMessage(), previous: $exception);
        }
    }
}
