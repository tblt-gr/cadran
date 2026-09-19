<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/**
 * Confirms a detected candidate, or records a schedule stated by hand. This is
 * the only door a recurrence comes into existence through: detection proposes
 * and writes nothing at all until this use case runs.
 */
final readonly class ConfirmRecurrence
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private RecurrenceFactory $factory,
        private OccurrenceGenerator $generator,
        private UuidGenerator $ids,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RecurrenceInput $input): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $input): array {
            $now = $this->calendar->now();
            $recurrence = $this->factory->create($this->ids->generate(), $context->workspace, $input, $now);
            $this->recurrences->add($recurrence);
            $this->generator->extend($recurrence, $recurrence->nextExpectedOn, $this->calendar->today());
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::CONFIRMED,
                RecurrenceAuditEvents::ENTITY, $recurrence->id,
                AuditDiff::creation(['intervalKind' => $recurrence->intervalKind->value, 'dayOfPeriod' => $recurrence->dayOfPeriod]),
            ));

            return RecurrenceView::from($recurrence);
        });
    }
}
