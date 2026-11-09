<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

/** Composes individual lifecycle rules inside one transaction, including their audit writes. */
final readonly class BulkGoalLifecycle
{
    public function __construct(
        private CallerWorkspaceContext $caller, private GoalRepository $goals,
        private TransactionBoundary $transactions, private StopGoal $stop, private DeleteGoal $delete,
    ) {
    }

    public function __invoke(BulkGoalInput $input, bool $stop): void
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages goals.');
        }
        $this->transactions->transactional(function () use ($context, $input, $stop): void {
            $items = $input->items;
            usort($items, static fn (array $a, array $b): int => $a['goalId'] <=> $b['goalId']);
            $selected = [];
            // Authorize the whole selection before returning any row-specific information.
            // Stable lock order serialises overlapping batches without inverse-order deadlocks.
            foreach ($items as $item) {
                $selected[$item['goalId']] = $this->goals->findForUpdate($context->workspace, $item['goalId'])
                    ?? throw new GoalNotFound('This selection is unavailable in this workspace.');
            }
            foreach ($items as $item) {
                if ($selected[$item['goalId']]->version !== $item['expectedVersion']) {
                    throw new GoalBulkConflict('stale_goal_version', $item['goalId'], 'version');
                }
            }
            foreach ($items as $item) {
                try {
                    if ($stop) {
                        ($this->stop)(new StopGoalInput($item['goalId'], $item['expectedVersion'], $item['lastPeriod'] ?? ''));
                    } else {
                        ($this->delete)($item['goalId'], $item['expectedVersion']);
                    }
                } catch (GoalConflict $error) {
                    throw new GoalBulkConflict($error->errorCode, $item['goalId'], 'stale_goal_version' === $error->errorCode ? 'version' : 'selection');
                } catch (GoalInputError $error) {
                    throw new GoalInputError($error->errorCode, 'A selected goal cannot be stopped.', ['goalId' => $item['goalId'], 'field' => 'lastPeriod']);
                }
            }
        });
    }
}
