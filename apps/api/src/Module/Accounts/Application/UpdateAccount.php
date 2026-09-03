<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceContext;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateAccount
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, UpdateAccountInput $input): AccountView
    {
        $context = $this->caller->resolveContext();
        $kind = AccountInputParser::kind($input->kind);
        $valuationMode = AccountInputParser::valuationMode($input->valuationMode);
        $liquidityLevel = AccountInputParser::liquidityLevel($input->liquidityLevel);
        $maskedIdentifier = AccountInputParser::maskedIdentifier($input->maskedIdentifier);
        $openedOn = AccountInputParser::businessDay($input->openedOn, 'opening date');
        $closedOn = AccountInputParser::optionalBusinessDay($input->closedOn, 'closing date');
        $label = trim($input->label);

        return $this->transactionBoundary->transactional(function () use (
            $id,
            $input,
            $context,
            $label,
            $kind,
            $maskedIdentifier,
            $valuationMode,
            $liquidityLevel,
            $openedOn,
            $closedOn,
        ): AccountView {
            $current = $this->accounts->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new AccountNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            // An archived account is read-only: report that before a label
            // check that would otherwise blame a label the archive itself freed.
            if (null !== $current->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }
            if ($this->accounts->hasActiveLabel($context->workspace, $label, $current->id)) {
                throw new AccountConflict('An active account already uses this label.');
            }

            try {
                $updated = $current->reconfigure(
                    label: $label,
                    kind: $kind,
                    maskedIdentifier: $maskedIdentifier,
                    valuationMode: $valuationMode,
                    liquidityLevel: $liquidityLevel,
                    includeInNetWorth: $input->includeInNetWorth,
                    includeInEmergencyFund: $input->includeInEmergencyFund,
                    openedOn: $openedOn,
                    closedOn: $closedOn,
                    updatedAt: $this->clock->now(),
                );
            } catch (InvalidAccount $exception) {
                throw new InvalidAccountInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->accounts->update($updated, $current->version)) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            $this->audit($context, $current, $updated);

            return AccountView::fromAccount($updated);
        });
    }

    /**
     * One event for the edit, plus a dedicated one when the account crossed its
     * closure boundary: closing and reopening change what reports count, so a
     * reviewer must find them without diffing every attribute of an update.
     */
    private function audit(WorkspaceContext $context, Account $before, Account $after): void
    {
        $this->record($context, $after->id, AccountAuditEvents::UPDATED, AuditDiff::change(
            AccountAuditFingerprint::of($before),
            AccountAuditFingerprint::of($after),
        ));

        if ($before->isClosed() === $after->isClosed()) {
            return;
        }

        $this->record(
            $context,
            $after->id,
            $after->isClosed() ? AccountAuditEvents::CLOSED : AccountAuditEvents::REOPENED,
            AuditDiff::change(
                ['closed' => $before->isClosed(), 'version' => $before->version],
                ['closed' => $after->isClosed(), 'version' => $after->version],
            ),
        );
    }

    private function record(WorkspaceContext $context, string $accountId, string $eventType, AuditDiff $diff): void
    {
        ($this->recordAuditEvent)(new AuditEventRecord(
            workspace: $context->workspace,
            actorId: $context->actorId,
            eventType: $eventType,
            entityType: AccountAuditEvents::ENTITY,
            entityId: $accountId,
            diff: $diff,
        ));
    }
}
