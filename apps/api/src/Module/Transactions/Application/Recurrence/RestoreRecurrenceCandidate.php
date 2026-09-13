<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDismissalRepository;

final readonly class RestoreRecurrenceCandidate
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private RecurrenceDismissalRepository $dismissals,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
    ) {
    }

    public function __invoke(string $fingerprint): void
    {
        $context = $this->caller->resolveContext();
        $this->boundary->transactional(function () use ($context, $fingerprint): void {
            if (!$this->dismissals->restore($context->workspace, $fingerprint)) {
                throw new RecurrenceNotFound();
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, RecurrenceAuditEvents::CANDIDATE_RESTORED,
                RecurrenceAuditEvents::CANDIDATE_ENTITY, $fingerprint,
                AuditDiff::change(['dismissed' => true], ['dismissed' => false]),
            ));
        });
    }
}
