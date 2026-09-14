<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\RecurrenceSchedule;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;

/**
 * Writes the forecast forward, and never backwards.
 *
 * Generation is expressed as "the instalments missing between a date and the
 * twelve-month horizon", which is what makes repeating it harmless: a horizon
 * that is already complete produces nothing, so the explicit refresh is
 * idempotent and no listing has to extend anything behind the reader's back.
 */
final readonly class OccurrenceGenerator
{
    public function __construct(
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private UuidGenerator $ids,
    ) {
    }

    /**
     * Generates the instalments missing between `$from` and the horizon twelve
     * months after `$today`, snapshotting the recurrence's current amount and
     * tolerance onto each one. Returns how many rows were written.
     */
    public function extend(TransactionRecurrence $recurrence, \DateTimeImmutable $from, \DateTimeImmutable $today): int
    {
        $schedule = RecurrenceFactory::schedule($recurrence->intervalKind, $recurrence->dayOfPeriod, $from);
        $existing = array_flip($this->occurrences->scheduledDatesFrom($recurrence->workspace, $recurrence->id, $from));

        $generated = [];
        foreach ($schedule->through(RecurrenceSchedule::horizon($today)) as $date) {
            if (array_key_exists($date->format('Y-m-d'), $existing)) {
                continue;
            }
            $generated[] = new TransactionRecurrenceOccurrence(
                $this->ids->generate(), $recurrence->workspace, $recurrence->id, $date,
                $recurrence->expectedAmount, $recurrence->amountTolerance, null, null, OccurrenceStatus::EXPECTED,
            );
        }
        $this->occurrences->addAll($generated);

        return count($generated);
    }

    /**
     * Where the schedule now points: the earliest instalment still awaiting a
     * movement, or the next scheduled date beyond the last generated one when
     * every instalment has been settled. Deriving it rather than incrementing
     * it is what keeps a settlement followed by its cancellation from moving
     * the pointer twice.
     */
    public function pointer(TransactionRecurrence $recurrence, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $earliest = $this->occurrences->earliestExpectedOn($recurrence->workspace, $recurrence->id);
        if (null !== $earliest) {
            return $earliest;
        }

        $last = $this->occurrences->lastExpectedOn($recurrence->workspace, $recurrence->id);
        $schedule = RecurrenceFactory::schedule($recurrence->intervalKind, $recurrence->dayOfPeriod, $last ?? $today);

        return null === $last ? $schedule->first() : $schedule->after($last);
    }

    public function lastExpectedOn(TransactionRecurrence $recurrence): ?\DateTimeImmutable
    {
        return $this->occurrences->lastExpectedOn($recurrence->workspace, $recurrence->id);
    }
}
