<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/**
 * Restores an archived schedule without altering its historic snapshots.
 *
 * The archive window may have left unmatched instalments stranded in the
 * past: without a reset they surface as LATE for a period the schedule was
 * not even active, and the earliest-unmatched pointer used by matching would
 * walk `next_expected_on` back into that gap. Restoring therefore replays the
 * same forward-only reset an edit performs: drop unmatched instalments from
 * today, regenerate the horizon, and recompute the pointer from what remains.
 */
final readonly class RestoreRecurrence
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private OccurrenceGenerator $generator,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $id, int $version): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $id, $version): array {
            $current = $this->recurrences->findForUpdate($context->workspace, $id) ?? throw new RecurrenceNotFound();
            if ($version !== $current->version) {
                throw new StaleRecurrence();
            }
            if (null === $current->archivedAt) {
                throw new StaleRecurrence(StaleRecurrence::NOT_ARCHIVED);
            }
            $today = $this->calendar->today();
            $restored = RecurrenceFactory::restore($current, $this->calendar->now());
            if (!$this->recurrences->update($restored, $current->version)) {
                throw new StaleRecurrence();
            }
            $this->occurrences->deleteUnmatchedFrom($context->workspace, $id, $today);
            $this->generator->extend($restored, $today, $today);
            $this->recurrences->advanceNextExpectedOn($context->workspace, $id, $this->generator->pointer($restored, $today));
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::RESTORED,
                RecurrenceAuditEvents::ENTITY, $id, AuditDiff::change(['archived' => true], ['archived' => false]),
            ));

            return $this->reread($context->workspace, $id);
        });
    }

    /** @return array<string, mixed> */
    private function reread(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): array
    {
        return RecurrenceView::from($this->recurrences->find($workspace, $id) ?? throw new RecurrenceNotFound());
    }
}
