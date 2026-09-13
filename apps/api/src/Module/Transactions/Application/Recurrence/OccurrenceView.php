<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Transactions\Domain\Recurrence\OccurrenceEffectiveStatus;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;

/**
 * An instalment as a reader sees it. The status is the effective one, so a
 * passed expectation reads `LATE` without the row ever being rewritten.
 */
final readonly class OccurrenceView
{
    /** @return array<string, mixed> */
    public static function from(TransactionRecurrenceOccurrence $occurrence, \DateTimeImmutable $today): array
    {
        return [
            'id' => $occurrence->id,
            'recurrenceId' => $occurrence->recurrenceId,
            'expectedOn' => $occurrence->expectedOn->format('Y-m-d'),
            'expectedAmount' => RecurrenceView::amount($occurrence->expectedAmount),
            'amountTolerance' => RecurrenceView::amount($occurrence->amountTolerance),
            'status' => OccurrenceEffectiveStatus::of($occurrence, $today)->value,
            'matchedTransactionId' => $occurrence->matchedTransactionId,
            'matchedAt' => $occurrence->matchedAt?->format(DATE_ATOM),
        ];
    }
}
