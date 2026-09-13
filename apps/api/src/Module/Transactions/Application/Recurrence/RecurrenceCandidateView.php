<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Transactions\Domain\Recurrence\RecurrenceCandidate;

/**
 * A proposal as the API states it. `confidence` is a label or nothing at all:
 * when the rules cannot classify a rhythm, the answer carries the reason
 * instead, never a zero and never an invented percentage.
 */
final readonly class RecurrenceCandidateView
{
    /** @return array<string, mixed> */
    public static function from(RecurrenceCandidate $candidate): array
    {
        return [
            'fingerprint' => $candidate->fingerprint,
            'accountId' => $candidate->accountId,
            'counterparty' => $candidate->counterparty,
            'intervalKind' => $candidate->intervalKind->value,
            'medianAmount' => RecurrenceView::amount($candidate->medianAmount),
            'tolerance' => RecurrenceView::amount($candidate->tolerance),
            'occurrenceCount' => $candidate->occurrenceCount,
            'firstSeenOn' => $candidate->firstSeenOn->format('Y-m-d'),
            'lastSeenOn' => $candidate->lastSeenOn->format('Y-m-d'),
            'confidence' => $candidate->confidence?->value,
            'confidenceReason' => $candidate->confidenceReason?->value,
        ];
    }
}
