<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * The rhythm of a recurrence, and the observed median gap it is recognised
 * from. The bounds are inclusive and deliberately narrow: a median that falls
 * between two of them names no rhythm at all, and the group it came from is
 * disqualified rather than forced into the nearest interval.
 */
enum RecurrenceIntervalKind: string
{
    case WEEKLY = 'WEEKLY';
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case YEARLY = 'YEARLY';

    /**
     * The median gap can end in `.5` when an even number of gaps was observed,
     * so the comparison is decimal and never a rounded integer.
     */
    public static function classify(DecimalValue $medianGapDays): ?self
    {
        foreach (self::cases() as $kind) {
            [$floor, $ceiling] = $kind->medianGapBounds();
            if ($medianGapDays->compareTo(DecimalValue::fromString((string) $floor)) >= 0
                && $medianGapDays->compareTo(DecimalValue::fromString((string) $ceiling)) <= 0) {
                return $kind;
            }
        }

        return null;
    }

    /** @return array{int, int} inclusive median gap bounds, in days */
    public function medianGapBounds(): array
    {
        return match ($this) {
            self::WEEKLY => [6, 8],
            self::MONTHLY => [27, 32],
            self::QUARTERLY => [88, 95],
            self::YEARLY => [360, 370],
        };
    }

    /** `day_of_period` is an ISO weekday for a weekly rhythm, a day of month otherwise. */
    public function accepts(int $dayOfPeriod): bool
    {
        return $dayOfPeriod >= 1 && $dayOfPeriod <= (self::WEEKLY === $this ? 7 : 31);
    }
}
