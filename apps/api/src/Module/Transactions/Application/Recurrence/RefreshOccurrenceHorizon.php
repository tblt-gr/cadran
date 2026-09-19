<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Transactions\Domain\Recurrence\RecurrenceSchedule;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

final readonly class RefreshOccurrenceHorizon
{
    private const int MAX_RECURRENCES = 1000;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private OccurrenceGenerator $generator,
        private TransactionBoundary $boundary,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return array{recurrencesExamined: int, occurrencesGenerated: int, horizonEndsOn: string} */
    public function __invoke(): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $today = $this->calendar->today();

        return $this->boundary->transactional(function () use ($workspace, $today): array {
            $recurrences = $this->recurrences->activeInOrder($workspace, self::MAX_RECURRENCES);
            $generated = 0;
            foreach ($recurrences as $recurrence) {
                $last = $this->generator->lastExpectedOn($recurrence);
                $from = null === $last ? $recurrence->nextExpectedOn : RecurrenceFactory::schedule(
                    $recurrence->intervalKind, $recurrence->dayOfPeriod, $last,
                )->after($last);
                $generated += $this->generator->extend($recurrence, $from, $today);
            }

            return [
                'recurrencesExamined' => count($recurrences),
                'occurrencesGenerated' => $generated,
                'horizonEndsOn' => RecurrenceSchedule::horizon($today)->format('Y-m-d'),
            ];
        });
    }
}
