<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\BulkEditPolicy;
use App\Module\Transactions\Domain\BulkEditSkipReason;
use App\Module\Transactions\Domain\BulkMovePolicy;
use App\Module\Transactions\Domain\BulkMoveTarget;
use App\Module\Transactions\Domain\Transaction;

/**
 * The single place that decides which rows of a bulk run are refused whole and what a move would do to the
 * balances. The preview and the apply both call it, so they cannot diverge.
 */
final readonly class EvaluateBulkRows
{
    public function __construct(
        private ResolveBulkScope $scope,
        private ReadBulkEditFacts $readFacts,
        private BulkMoveAccounts $accounts,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /**
     * @param list<string>               $ids    the sorted scope
     * @param array<string, Transaction> $rows   the rows found, by identifier
     * @param ?BulkMoveTarget            $target the requested account, null when none is requested
     */
    public function __invoke(WorkspaceScope $workspace, BulkEditInput $input, array $ids, array $rows, ?BulkMoveTarget $target): BulkRowEvaluation
    {
        $changes = $input->changes;
        $moving = $changes->moves();
        // The scope was resolved before the rows were read: a concurrent edit may have moved one out of the
        // filter since, so membership is verified again now.
        $stillMatching = null === $input->ids ? array_flip($this->scope->matchingIds($workspace, $input)) : null;
        $facts = ($this->readFacts)($workspace, $rows, $moving, $changes->bookedOn);
        $currents = $moving ? $this->accounts->currents($workspace, $rows) : [];
        $today = $moving ? $this->calendar->today() : null;

        $skipped = [];
        $eligible = [];
        foreach ($ids as $id) {
            $current = $rows[$id] ?? null;
            if (null === $current) {
                $skipped[$id] = BulkEditSkipReason::NOT_FOUND;
                continue;
            }
            if (null !== $stillMatching && !isset($stillMatching[$id])) {
                $skipped[$id] = BulkEditSkipReason::NO_LONGER_MATCHING;
                continue;
            }
            $reason = BulkEditPolicy::rowReason($current, $facts[$id], $changes->label?->valueFor($id));
            if (null === $reason && null !== $today) {
                $reason = BulkMovePolicy::reason($current, $facts[$id], $target, $currents[$current->accountId], $changes->bookedOn, $today);
            }
            if (null !== $reason) {
                $skipped[$id] = $reason;
                continue;
            }
            $eligible[$id] = $current;
        }

        return new BulkRowEvaluation($skipped, $eligible, $facts, $moving ? ComputeBulkMoveImpact::of(array_values($eligible), $currents, $target, $changes->bookedOn) : []);
    }
}
