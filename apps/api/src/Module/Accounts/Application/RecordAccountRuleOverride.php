<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Accounts\Domain\InvalidAccountRuleOverride;
use App\Module\Accounts\Domain\OverlappingAccountRuleOverride;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a dated rule value against one account, in front of what it
 * inherits.
 *
 * The account row is locked for the whole operation. Two overrides of the same
 * kind covering the same day would leave "the ceiling this account claims on
 * 12 March" with two answers, and the read side has no way to choose; the lock
 * serialises writers on one account and the database repeats the refusal for
 * anything that reaches the table another way.
 */
final readonly class RecordAccountRuleOverride
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountRuleOverrideRepository $overrides,
        private ResolveAccountRuleAuthority $authority,
        private SubmittedAccountRuleOverride $submitted,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, AccountRuleOverrideInput $input): AccountRuleOverride
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $input, $context): AccountRuleOverride {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound('No account carries this identifier in this workspace.');
            }

            if (null !== $account->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            $override = $this->submitted->toOverride(
                $account,
                $this->authority->of($account),
                $input,
                $context->actorId,
                $this->clock->now(),
            );

            $current = $this->overrides->findForAccount($context->workspace, $account->id);

            try {
                // Built for its invariants rather than to be stored: the row is
                // inserted on its own below, and the database repeats the
                // non-overlap check for any writer that does not come through
                // here.
                $current->appended($override);
            } catch (OverlappingAccountRuleOverride $collision) {
                throw new AccountRuleOverrideConflict($collision->getMessage(), previous: $collision);
            } catch (InvalidAccountRuleOverride $failure) {
                throw new InvalidAccountRuleOverrideInput($failure->getMessage(), previous: $failure);
            }

            $this->overrides->add($override);

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountRuleOverrideAuditEvents::RECORDED,
                entityType: AccountRuleOverrideAuditEvents::ENTITY,
                entityId: $override->id,
                diff: AuditDiff::creation(AccountRuleOverrideAuditFingerprint::of($override)),
            ));

            return $override;
        });
    }
}
