<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * What a rate scale answers for one balance: the interest it would credit,
 * the effective percentage, and which bracket the balance reached.
 *
 * A zero or negative balance cannot produce an effective rate. Zero interest
 * is a real figure; a missing effective percentage is not — inventing 0 %
 * would read as "this scale pays nothing".
 */
final readonly class AppliedRate
{
    public const string UNSETTLED_ZERO_BALANCE = 'ZERO_BALANCE';
    public const string UNSETTLED_NEGATIVE_BALANCE = 'NEGATIVE_BALANCE';

    public function __construct(
        public ?DecimalValue $interest,
        public ?DecimalValue $effectivePercentage,
        public DecimalValue $reachedPercentage,
        public DecimalValue $reachedLowerBound,
        public bool $rateShiftsAboveFirstBracket,
        public ?string $unsettledReason = null,
    ) {
    }
}
