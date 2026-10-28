<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

/**
 * Moves one active group among its siblings under the same parent.
 *
 * Every sibling row is locked, then the active order the caller displayed is
 * compared with the saved one, so two moves at once cannot both win: the
 * second one is refused. The new order covers hidden archived siblings too,
 * so moving a visible row never reshuffles one the owner cannot see.
 */
final readonly class MoveAccountGroup
{
    /** A personal tree holds tens of siblings; the cap only stops an unbounded rewrite. */
    public const int MAX_SIBLINGS = 1000;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountGroupRepository $groups,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    /**
     * @param list<string> $expectedSiblingIds the active sibling order the caller displayed, moved group included
     *
     * @throws AccountGroupNotFound    when the group is unknown to the caller's workspace
     * @throws AccountGroupArchived    when the group is archived
     * @throws InvalidAccountGroupMove when the anchor is not another active sibling, or the siblings are too many to rewrite
     * @throws StaleAccountGroupOrder  when the displayed order is not the saved one
     */
    public function __invoke(string $groupId, ?string $beforeGroupId, array $expectedSiblingIds): AccountGroupMoveView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($groupId, $beforeGroupId, $expectedSiblingIds, $context): AccountGroupMoveView {
            $group = $this->groups->findForUpdate($context->workspace, $groupId);
            if (null === $group) {
                throw new AccountGroupNotFound();
            }
            if (null !== $group->archivedAt) {
                throw new AccountGroupArchived('An archived group cannot be moved.');
            }

            $siblings = $this->groups->lockSiblings($context->workspace, $group->parentId, self::MAX_SIBLINGS + 1);
            if (count($siblings) > self::MAX_SIBLINGS) {
                throw new InvalidAccountGroupMove('This group has more siblings than one reorder rewrites.', 'too_many_siblings');
            }

            $active = self::ids(array_filter($siblings, static fn (AccountGroup $sibling): bool => null === $sibling->archivedAt));
            if (null !== $beforeGroupId && ($beforeGroupId === $groupId || !in_array($beforeGroupId, $active, true))) {
                throw new InvalidAccountGroupMove('The anchor must be another active sibling of the moved group.');
            }
            if ($active !== $expectedSiblingIds) {
                throw new StaleAccountGroupOrder('The order of these groups was changed by another request.');
            }

            $full = self::ids($siblings);
            $moved = AccountOrdering::move($full, $groupId, $beforeGroupId);
            $newActive = array_values(array_filter($moved, static fn (string $id): bool => in_array($id, $active, true)));
            $rank = (int) array_search($groupId, $newActive, true) + 1;
            if ($moved === $full) {
                return new AccountGroupMoveView($group->parentId, $newActive, $rank);
            }

            $this->groups->saveSiblingOrder($context->workspace, $group->parentId, $moved);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountGroupAuditEvents::MOVED,
                entityType: AccountGroupAuditEvents::ENTITY,
                entityId: $groupId,
                diff: AuditDiff::change(
                    ['rank' => (int) array_search($groupId, $active, true) + 1, 'siblingCount' => count($active)],
                    ['rank' => $rank, 'siblingCount' => count($active)],
                ),
            ));

            return new AccountGroupMoveView($group->parentId, $newActive, $rank);
        });
    }

    /**
     * @param iterable<AccountGroup> $groups
     *
     * @return list<string>
     */
    private static function ids(iterable $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            $ids[] = $group->id;
        }

        return $ids;
    }
}
