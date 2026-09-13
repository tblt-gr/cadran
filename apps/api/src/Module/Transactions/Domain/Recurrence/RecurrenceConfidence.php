<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * How firmly a detected rhythm is established, as a label and never as a
 * figure. A percentage would read as a guarantee the history cannot give, so a
 * spread the table does not cover yields no confidence at all — the caller then
 * carries a {@see RecurrenceConfidenceReason} instead of a fabricated number.
 */
enum RecurrenceConfidence: string
{
    case HIGH = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case LOW = 'LOW';

    /**
     * The qualitative table: more observations and a tighter spread read
     * higher. `$maxGapDeviationDays` is the widest distance between an observed
     * gap and the median gap, decimal because that median may end in `.5`.
     */
    public static function classify(int $occurrenceCount, DecimalValue $maxGapDeviationDays): ?self
    {
        return match (true) {
            $occurrenceCount >= 6 && self::within($maxGapDeviationDays, 2) => self::HIGH,
            $occurrenceCount >= 4 && self::within($maxGapDeviationDays, 3) => self::MEDIUM,
            3 === $occurrenceCount && self::within($maxGapDeviationDays, 4) => self::LOW,
            default => null,
        };
    }

    private static function within(DecimalValue $deviation, int $days): bool
    {
        return $deviation->compareTo(DecimalValue::fromString((string) $days)) <= 0;
    }
}
