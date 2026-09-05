<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateAccountGroup
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountGroupRepository $groups,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateAccountGroupInput $input): AccountGroupView
    {
        $context = $this->caller->resolveContext();
        $parentId = AccountGroupInputParser::optionalIdentifier($input->parentId);
        $label = trim($input->label);

        return $this->transactionBoundary->transactional(function () use ($context, $parentId, $label, $input): AccountGroupView {
            $parent = null;
            if (null !== $parentId) {
                $parent = $this->groups->findForUpdate($context->workspace, $parentId);
                if (null === $parent || null !== $parent->archivedAt) {
                    throw new InvalidAccountGroupInput('The group parent must be an active group in this workspace.');
                }
            }

            if ($this->groups->hasActiveSiblingLabel($context->workspace, $parentId, $label)) {
                throw new AccountGroupConflict('An active sibling already uses this label.');
            }

            try {
                $now = $this->clock->now();
                $group = new AccountGroup(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    label: $label,
                    parentId: $parentId,
                    sortOrder: $input->sortOrder,
                    depth: null === $parent ? 1 : $parent->depth + 1,
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidAccountGroup $exception) {
                throw new InvalidAccountGroupInput($exception->getMessage(), previous: $exception);
            }

            $this->groups->add($group);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountGroupAuditEvents::CREATED,
                entityType: AccountGroupAuditEvents::ENTITY,
                entityId: $group->id,
                diff: AuditDiff::creation(AccountGroupAuditFingerprint::of($group)),
            ));

            return AccountGroupView::fromGroup($group, false, $parent?->label);
        });
    }
}
