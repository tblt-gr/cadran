<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Accounts\Domain\InvalidPeriodClosure;
use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\UuidGenerator;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Closes one month of the workspace.
 *
 * The closing lock is taken before anything is read, so the checks describe
 * the month as it will be committed: a write that was in flight has finished,
 * and every later one meets the closure. A failing check refuses the closing
 * unless the caller confirms that exact condition with a reason, and only the
 * owner may do so.
 */
final readonly class ClosePeriod
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PeriodClosureRepository $closures,
        private AssessPeriodClosing $assess,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private WorkspaceCalendar $calendar,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ClosePeriodInput $input): PeriodClosure
    {
        $context = $this->caller->resolveContext();
        $month = self::month($input->period);
        $overrides = self::overrides($input->overrides);
        if ([] !== $overrides && !$context->isOwner) {
            throw new PeriodClosureForbidden('Only the workspace owner may override a closing condition.');
        }

        $closure = $this->transactionBoundary->transactional(function () use ($context, $month, $overrides): PeriodClosure {
            $workspace = $context->workspace;
            $this->closures->lockExclusive($workspace);
            if ($month->lastDay() >= $this->calendar->today()) {
                throw new InvalidPeriodClosureInput('A period can be closed only once it has ended.');
            }
            if (null !== $this->closures->findActive($workspace, $month)) {
                throw new PeriodClosureConflict('This period is already closed.');
            }

            $blockers = ($this->assess)($workspace, $month);
            $failing = array_map(static fn (PeriodClosingBlocker $blocker): string => $blocker->condition->value, $blockers);
            if ([] !== array_diff(array_keys($overrides), $failing)) {
                throw new InvalidPeriodClosureInput('A confirmation names a condition that does not block this period.');
            }
            $unconfirmed = array_values(array_filter(
                $blockers,
                static fn (PeriodClosingBlocker $blocker): bool => !array_key_exists($blocker->condition->value, $overrides),
            ));
            if ([] !== $unconfirmed) {
                throw new PeriodClosingBlocked($unconfirmed);
            }

            $closure = new PeriodClosure(
                id: $this->uuidGenerator->generate(),
                workspace: $workspace,
                month: $month,
                closedAt: $this->calendar->now(),
                closedBy: $context->actorId,
                version: 1,
            );
            $this->closures->add($closure);
            ($this->recordAuditEvent)(new AuditEventRecord(
                $workspace, $context->actorId, PeriodClosureAuditEvents::CLOSED,
                PeriodClosureAuditEvents::ENTITY, $closure->id,
                AuditDiff::creation(PeriodClosureAuditFingerprint::of($closure, $overrides)),
            ));

            return $closure;
        });
        // After the commit: a listener reading the closed month must see the closure it belongs to.
        $this->events->dispatch(new PeriodClosedEvent($context->workspace, $month, $closure->id));

        return $closure;
    }

    private static function month(string $period): CalendarMonth
    {
        try {
            return CalendarMonth::fromString($period);
        } catch (InvalidCalendarMonth $exception) {
            throw new InvalidPeriodClosureInput($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<string, string> $submitted
     *
     * @return array<string, string>
     */
    private static function overrides(array $submitted): array
    {
        $overrides = [];
        foreach ($submitted as $condition => $reason) {
            if (null === PeriodClosingCondition::tryFrom((string) $condition)) {
                throw new InvalidPeriodClosureInput('A confirmation names an unknown condition.');
            }
            try {
                $overrides[(string) $condition] = PeriodClosure::normalisedReason($reason);
            } catch (InvalidPeriodClosure $exception) {
                throw new InvalidPeriodClosureInput($exception->getMessage(), previous: $exception);
            }
        }

        return $overrides;
    }
}
