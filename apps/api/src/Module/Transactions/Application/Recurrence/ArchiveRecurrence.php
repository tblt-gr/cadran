<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/**
 * Stops a recurrence without erasing it. Its instalments stay readable, and a
 * new movement can no longer settle one: an archived schedule is out of the
 * matching set, not out of the history.
 */
final readonly class ArchiveRecurrence
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
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
            if (null !== $current->archivedAt) {
                throw new StaleRecurrence(StaleRecurrence::ARCHIVED);
            }
            $archived = RecurrenceFactory::archive($current, $this->calendar->now());
            if (!$this->recurrences->update($archived, $current->version)) {
                throw new StaleRecurrence();
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::ARCHIVED,
                RecurrenceAuditEvents::ENTITY, $id, AuditDiff::change(['archived' => false], ['archived' => true]),
            ));

            return RecurrenceView::from($archived);
        });
    }
}
