<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One booked movement as detection reads it: just enough of a transaction to
 * group it, date it and compare its amount. Detection never holds a whole
 * transaction, because it may not change one.
 *
 * `$groupingKey` is `coalesce(lower(counterparty), normalized_label)`, computed
 * by the query so that grouping and the database agree on one normalisation.
 * `$displayName` is the readable counterparty, or the raw label when the
 * movement carries none.
 */
final readonly class RecurrenceObservation
{
    public function __construct(
        public string $transactionId,
        public WorkspaceScope $workspace,
        public string $accountId,
        public string $groupingKey,
        public string $displayName,
        public \DateTimeImmutable $bookedOn,
        public AssetAmount $amount,
    ) {
    }
}
