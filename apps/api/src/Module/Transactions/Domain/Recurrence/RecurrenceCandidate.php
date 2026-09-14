<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * A rhythm read from the history and offered to the user. It is a proposal and
 * nothing else: no recurrence, occurrence or transaction exists until the user
 * confirms it explicitly.
 */
final readonly class RecurrenceCandidate
{
    public function __construct(
        public string $fingerprint,
        public string $accountId,
        public string $groupingKey,
        public string $counterparty,
        public RecurrenceIntervalKind $intervalKind,
        public DecimalValue $medianGapDays,
        public AssetAmount $medianAmount,
        public AssetAmount $tolerance,
        public int $occurrenceCount,
        public \DateTimeImmutable $firstSeenOn,
        public \DateTimeImmutable $lastSeenOn,
        public ?RecurrenceConfidence $confidence,
        public ?RecurrenceConfidenceReason $confidenceReason,
    ) {
        if ((null === $confidence) !== (null !== $confidenceReason)) {
            throw new InvalidRecurrence('A candidate states either a confidence or the reason it has none.');
        }
    }
}
