<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDismissalRepository;

final readonly class DismissRecurrenceCandidate
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private RecurrenceDismissalRepository $dismissals,
        private UuidGenerator $ids,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $fingerprint): void
    {
        $context = $this->caller->resolveContext();
        $this->boundary->transactional(function () use ($context, $fingerprint): void {
            if (null !== $this->dismissals->findId($context->workspace, $fingerprint)) {
                return;
            }
            $id = $this->ids->generate();
            $this->dismissals->dismiss($context->workspace, $id, $fingerprint, $this->calendar->now());
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::CANDIDATE_DISMISSED,
                RecurrenceAuditEvents::CANDIDATE_ENTITY, $id, AuditDiff::creation(['dismissed' => true]),
            ));
        });
    }
}
