<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Accounts\Domain\InvalidPeriodClosure;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Reopens a closed month. Ownership is checked on the resolved caller of this
 * request, not remembered from the closing: who may reopen is decided now.
 */
final readonly class ReopenPeriod
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PeriodClosureRepository $closures,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private WorkspaceCalendar $calendar,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(string $period, int $version, string $reason): PeriodClosure
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new PeriodClosureForbidden('Only the workspace owner may reopen a period.');
        }
        try {
            $month = CalendarMonth::fromString($period);
            $reason = PeriodClosure::normalisedReason($reason);
        } catch (InvalidCalendarMonth|InvalidPeriodClosure $exception) {
            throw new InvalidPeriodClosureInput($exception->getMessage(), previous: $exception);
        }

        $reopened = $this->transactionBoundary->transactional(function () use ($context, $month, $version, $reason): PeriodClosure {
            $workspace = $context->workspace;
            $this->closures->lockExclusive($workspace);
            $current = $this->closures->findActive($workspace, $month);
            if (null === $current) {
                throw new PeriodClosureNotFound('This period is not closed.');
            }
            if ($current->version !== $version) {
                throw new StalePeriodClosureVersion('The closure changed concurrently.');
            }
            $reopened = $current->reopen($reason, $this->calendar->now());
            if (!$this->closures->update($reopened, $current->version)) {
                throw new StalePeriodClosureVersion('The closure changed concurrently.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                $workspace, $context->actorId, PeriodClosureAuditEvents::REOPENED,
                PeriodClosureAuditEvents::ENTITY, $reopened->id,
                AuditDiff::change(PeriodClosureAuditFingerprint::of($current), PeriodClosureAuditFingerprint::of($reopened)),
            ));

            return $reopened;
        });
        $this->events->dispatch(new PeriodReopenedEvent($context->workspace, $month, $reopened->id));

        return $reopened;
    }
}
