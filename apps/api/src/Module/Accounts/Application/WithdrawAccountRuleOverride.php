<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Withdraws an override, so the account resolves against what it inherits
 * again.
 *
 * Withdrawing is not the same as ending. Ending an override is a dated fact
 * recorded in its period, and the account keeps resolving against it up to
 * that day for ever after. Withdrawing says the claim should not have applied
 * at all: it stops answering on every date, including past ones, and the
 * inherited rule of each of those dates takes over again — the dated one, not
 * whatever is in force today.
 *
 * The row is kept. Deleting it would erase who claimed what and why, which is
 * the provenance an override exists to preserve.
 */
final readonly class WithdrawAccountRuleOverride
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountRuleOverrideRepository $overrides,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, string $overrideId): AccountRuleOverride
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $overrideId, $context): AccountRuleOverride {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound('No account carries this identifier in this workspace.');
            }

            if (null !== $account->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            $current = $this->overrides->findForAccount($context->workspace, $account->id);
            $target = $current->find($overrideId);
            if (null === $target) {
                throw new AccountRuleOverrideNotFound('No override carries this identifier on this account.');
            }

            if (!$target->isStanding()) {
                throw new AccountRuleOverrideConflict('This override was already withdrawn.');
            }

            $withdrawn = $target->withdrawnBy($context->actorId, $this->clock->now());

            if (!$this->overrides->withdraw($withdrawn)) {
                throw new AccountRuleOverrideConflict('This override was withdrawn by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountRuleOverrideAuditEvents::WITHDRAWN,
                entityType: AccountRuleOverrideAuditEvents::ENTITY,
                entityId: $withdrawn->id,
                diff: AuditDiff::change(
                    AccountRuleOverrideAuditFingerprint::of($target),
                    AccountRuleOverrideAuditFingerprint::of($withdrawn),
                ),
            ));

            return $withdrawn;
        });
    }
}
