<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Transactions\Domain\Reconciliation\ReviewReason;
use App\Module\Transactions\Domain\Transaction;

/**
 * What the matching rule concluded about one incoming booked movement: either
 * the single pending row it settles, or the reason a human has to decide and
 * the rows they are choosing between.
 */
final readonly class IncomingMovementMatch
{
    /** @param list<Transaction> $candidates */
    private function __construct(
        public ?Transaction $settles,
        public array $candidates,
        public ?ReviewReason $reviewReason,
    ) {
    }

    public static function settling(Transaction $candidate): self
    {
        return new self($candidate, [], null);
    }

    /** @param list<Transaction> $candidates */
    public static function ambiguous(array $candidates): self
    {
        return new self(null, $candidates, ReviewReason::AMBIGUOUS_MATCH);
    }

    public static function absent(): self
    {
        return new self(null, [], ReviewReason::NO_MATCH);
    }
}
