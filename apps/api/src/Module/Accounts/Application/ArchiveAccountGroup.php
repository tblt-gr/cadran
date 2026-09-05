<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

final readonly class ArchiveAccountGroup
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountGroupRepository $groups,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $expectedVersion): AccountGroupView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($id, $expectedVersion, $context): AccountGroupView {
            $current = $this->groups->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new AccountGroupNotFound();
            }
            if ($expectedVersion !== $current->version) {
                throw new StaleAccountGroupVersion('The group was changed by another request.');
            }
            if (null !== $current->archivedAt) {
                throw new AccountGroupArchived('An archived group is read-only.');
            }
            if ($this->groups->hasChildren($context->workspace, $current->id)) {
                throw new InvalidAccountGroupInput('A group with children cannot be archived.');
            }
            if ($this->groups->isReferencedByAccount($context->workspace, $current->id)) {
                throw new InvalidAccountGroupInput('A group assigned to an account cannot be archived.');
            }

            try {
                $archived = $current->archive($this->clock->now());
            } catch (InvalidAccountGroup $exception) {
                throw new InvalidAccountGroupInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->groups->update($archived, $current->version)) {
                throw new StaleAccountGroupVersion('The group was changed by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountGroupAuditEvents::ARCHIVED,
                entityType: AccountGroupAuditEvents::ENTITY,
                entityId: $archived->id,
                diff: AuditDiff::change(
                    AccountGroupAuditFingerprint::of($current),
                    AccountGroupAuditFingerprint::of($archived),
                ),
            ));

            return AccountGroupView::fromGroup($archived, false, null);
        });
    }
}
