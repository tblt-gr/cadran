<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColorMode;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateAccountGroup
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountGroupRepository $groups,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
        private ReadGroupColors $readGroupColors,
    ) {
    }

    public function __invoke(string $id, UpdateAccountGroupInput $input): AccountGroupView
    {
        $context = $this->caller->resolveContext();
        $parentId = AccountGroupInputParser::optionalIdentifier($input->parentId);
        $label = trim($input->label);

        return $this->transactionBoundary->transactional(function () use ($id, $input, $context, $parentId, $label): AccountGroupView {
            $current = $this->groups->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new AccountGroupNotFound();
            }
            if ($input->version !== $current->version) {
                throw new StaleAccountGroupVersion('The group was changed by another request.');
            }
            if (null !== $current->archivedAt) {
                throw new AccountGroupArchived('An archived group is read-only.');
            }

            $parent = null;
            $depth = 1;
            if (null !== $parentId) {
                $parent = $this->groups->findForUpdate($context->workspace, $parentId);
                if (null === $parent || null !== $parent->archivedAt) {
                    throw new InvalidAccountGroupInput('The group parent must be an active group in this workspace.');
                }

                if (AccountGroup::wouldCycle($current->id, $parentId, fn (string $candidate): ?string => $this->groups->find($context->workspace, $candidate)?->parentId)) {
                    throw new InvalidAccountGroupInput('A group tree cannot contain a cycle.');
                }

                $depth = $parent->depth + 1;
            }

            if ($this->groups->hasActiveSiblingLabel($context->workspace, $parentId, $label, $current->id)) {
                throw new AccountGroupConflict('An active sibling already uses this label.');
            }

            $now = $this->clock->now();
            $descendants = [];
            $descendantDepths = [];
            if ($current->parentId !== $parentId) {
                $descendants = $this->groups->descendantsForUpdate($context->workspace, $current->id);
                try {
                    $descendantDepths = AccountGroupTree::rebasedDepths($current->id, $depth, $descendants);
                } catch (InvalidAccountGroup $exception) {
                    throw new InvalidAccountGroupInput($exception->getMessage(), previous: $exception);
                }
            }

            [$mode, $color] = self::colorOf($current, $input, $parentId);

            try {
                $updated = $current->reconfigure(
                    label: $label,
                    parentId: $parentId,
                    sortOrder: $input->sortOrder,
                    depth: $depth,
                    updatedAt: $now,
                    color: $color,
                    colorMode: $mode,
                    colorSlot: $current->parentId === $parentId
                        ? $current->colorSlot
                        : (null === $parentId ? 0 : AccountGroup::nextColorSlot($this->groups->usedColorSlots($context->workspace, $parentId))),
                );
            } catch (InvalidAccountGroup $exception) {
                throw new InvalidAccountGroupInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->groups->update($updated, $current->version)) {
                throw new StaleAccountGroupVersion('The group was changed by another request.');
            }

            foreach ($descendants as $descendant) {
                $nextDepth = $descendantDepths[$descendant->id] ?? null;
                if (null === $nextDepth || $nextDepth === $descendant->depth) {
                    continue;
                }

                $rebased = $descendant->rebaseDepth($nextDepth, $now);
                if (!$this->groups->update($rebased, $descendant->version)) {
                    throw new StaleAccountGroupVersion('The group was changed by another request.');
                }
            }

            $parentChanged = $current->parentId !== $updated->parentId;
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountGroupAuditEvents::UPDATED,
                entityType: AccountGroupAuditEvents::ENTITY,
                entityId: $updated->id,
                diff: AuditDiff::change(
                    AccountGroupAuditFingerprint::of($current, false),
                    AccountGroupAuditFingerprint::of($updated, $parentChanged),
                ),
            ));

            return AccountGroupView::fromGroup(
                $updated,
                $this->groups->hasChildren($context->workspace, $updated->id),
                $parent?->label,
                ($this->readGroupColors)($context->workspace),
            );
        });
    }

    /**
     * An explicit mode wins, then an explicit color (the pre-mode payload), then
     * what the group already has. A group that leaves its parent behind cannot
     * keep inheriting from it.
     *
     * @return array{?AccountGroupColorMode, ?string}
     */
    private static function colorOf(AccountGroup $current, UpdateAccountGroupInput $input, ?string $parentId): array
    {
        if (null !== $input->colorMode) {
            return [$input->colorMode, $input->color];
        }
        if ($input->colorProvided) {
            return [null, $input->color];
        }
        if (AccountGroupColorMode::PARENT === $current->colorMode && null === $parentId) {
            return [AccountGroupColorMode::AUTO, null];
        }

        return [$current->colorMode, $current->color];
    }
}
