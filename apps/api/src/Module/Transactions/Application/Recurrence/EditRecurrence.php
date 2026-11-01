<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Transactions\Domain\Recurrence\RecurrenceSchedule;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/**
 * Edits a schedule forward only.
 *
 * Instalments in the past, and every instalment a real movement already
 * settled, are left exactly as they were recorded: they explain what was
 * expected at the time, and a later amount change must not rewrite that story.
 * Only unmatched instalments from today onwards are dropped and generated
 * again from the edited schedule.
 */
final readonly class EditRecurrence
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private RecurrenceFactory $factory,
        private OccurrenceGenerator $generator,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
        private DiscardGeneratedTransactions $discard,
        private MaterializeRecurrenceOccurrences $materialize,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $id, RecurrenceEditInput $input): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $id, $input): array {
            $current = $this->recurrences->findForUpdate($context->workspace, $id) ?? throw new RecurrenceNotFound();
            if ($input->version !== $current->version) {
                throw new StaleRecurrence();
            }
            if (null !== $current->archivedAt) {
                throw new StaleRecurrence(StaleRecurrence::ARCHIVED);
            }
            $now = $this->calendar->now();
            $today = $this->calendar->today();
            $from = $this->periodStart($input->firstExpectedOn, $today);
            $edited = $this->factory->revise($current, $input, $now);
            if (!$this->recurrences->update($edited, $current->version)) {
                throw new StaleRecurrence();
            }
            // Untouched generated rows go first: their instalments are then unmatched and unlinked, so the
            // deletion below can drop them and the schedule regenerates them from the edited recurrence.
            ($this->discard)($context, $current, $today);
            $this->occurrences->deleteUnmatchedFrom($context->workspace, $id, $today);
            $this->generator->extend($edited, $from, $today);
            $this->recurrences->advanceNextExpectedOn($context->workspace, $id, $this->generator->pointer($edited, $today));
            if ($edited->autoCreate) {
                $this->materialize->forRecurrence($context, $edited, $today);
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::UPDATED,
                RecurrenceAuditEvents::ENTITY, $id,
                AuditDiff::change(
                    ['intervalKind' => $current->intervalKind->value, 'dayOfPeriod' => $current->dayOfPeriod, 'autoCreate' => $current->autoCreate],
                    ['intervalKind' => $edited->intervalKind->value, 'dayOfPeriod' => $edited->dayOfPeriod, 'autoCreate' => $edited->autoCreate],
                ),
            ));

            return $this->reread($context->workspace, $id);
        });
    }

    /** The day regeneration starts from: today, or a later start the owner chose within the horizon. */
    private function periodStart(?string $literal, \DateTimeImmutable $today): \DateTimeImmutable
    {
        if (null === $literal) {
            return $today;
        }
        $start = RecurrenceFactory::day($literal);
        if ($start > RecurrenceSchedule::horizon($today)) {
            throw new InvalidRecurrenceInput('A recurrence cannot start beyond its twelve month horizon.');
        }

        return max($today, $start);
    }

    /** @return array<string, mixed> */
    private function reread(\App\Module\Foundation\Domain\WorkspaceScope $workspace, string $id): array
    {
        return RecurrenceView::from($this->recurrences->find($workspace, $id) ?? throw new RecurrenceNotFound());
    }
}
