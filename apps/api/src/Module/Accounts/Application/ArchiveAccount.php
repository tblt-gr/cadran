<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Takes an account out of the working set without deleting it: history, future
 * balances and reports keep a row to point at.
 */
final readonly class ArchiveAccount
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
        private ResolveAccountValuation $valuations,
    ) {
    }

    public function __invoke(string $id, int $expectedVersion): AccountView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($id, $expectedVersion, $context): AccountView {
            $current = $this->accounts->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new AccountNotFound();
            }
            if ($expectedVersion !== $current->version) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            if (null !== $current->archivedAt) {
                throw new AccountArchived('An archived account is read-only.');
            }

            try {
                $archived = $current->archive($this->clock->now());
            } catch (InvalidAccount $exception) {
                throw new InvalidAccountInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->accounts->update($archived, $current->version)) {
                throw new StaleAccountVersion('The account was changed by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountAuditEvents::ARCHIVED,
                entityType: AccountAuditEvents::ENTITY,
                entityId: $archived->id,
                diff: AuditDiff::change(
                    AccountAuditFingerprint::of($current),
                    AccountAuditFingerprint::of($archived),
                ),
            ));

            return AccountView::fromAccount($archived, valuation: $this->valuations->current($context->workspace, $archived));
        });
    }
}
