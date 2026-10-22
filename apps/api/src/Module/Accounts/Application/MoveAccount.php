<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\InvalidAccountMove;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

/**
 * Moves one account inside its own primary group, or the ungrouped section.
 *
 * The move rewrites the sequence of every member, hidden ones included, under
 * the row lock of the group order, so two owners reordering at once cannot
 * both win: the second one reads a stale version and is refused. It never
 * reassigns the account to another group.
 */
final readonly class MoveAccount
{
    /** A personal group holds tens of accounts; the cap only stops an unbounded rewrite. */
    public const int MAX_GROUP_MEMBERS = 1000;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AccountOrderRepository $orders,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    /**
     * @throws AccountNotFound    when the account is unknown to the caller's workspace
     * @throws InvalidAccountMove when the group or the anchor is not the account's own
     * @throws StaleAccountOrder  when the submitted version is not the saved one
     */
    public function __invoke(string $accountId, ?string $groupId, ?string $beforeAccountId, int $orderVersion): AccountMoveView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($accountId, $groupId, $beforeAccountId, $orderVersion, $context): AccountMoveView {
            $account = $this->accounts->findForUpdate($context->workspace, $accountId);
            if (null === $account) {
                throw new AccountNotFound();
            }
            if ($account->primaryGroupId !== $groupId) {
                throw new InvalidAccountMove('The account does not belong to this group.');
            }

            $current = $this->orders->lockVersion($context->workspace, $groupId);
            if ($current !== $orderVersion) {
                throw new StaleAccountOrder('The order of this group was changed by another request.');
            }

            $members = $this->accounts->memberIdsInOrder($context->workspace, $groupId, self::MAX_GROUP_MEMBERS + 1);
            if (count($members) > self::MAX_GROUP_MEMBERS) {
                throw new InvalidAccountMove('This group holds more accounts than one reorder rewrites.');
            }

            $moved = AccountOrdering::move($members, $accountId, $beforeAccountId);
            $rank = (int) array_search($accountId, $moved, true) + 1;
            if ($moved === $members) {
                return new AccountMoveView($groupId, $current, $rank);
            }

            $this->orders->save($context->workspace, $groupId, $moved, $current + 1);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountAuditEvents::MOVED,
                entityType: AccountAuditEvents::ENTITY,
                entityId: $accountId,
                diff: AuditDiff::change(
                    ['rank' => (int) array_search($accountId, $members, true) + 1, 'orderVersion' => $current],
                    ['rank' => $rank, 'orderVersion' => $current + 1],
                ),
            ));

            return new AccountMoveView($groupId, $current + 1, $rank);
        });
    }
}
