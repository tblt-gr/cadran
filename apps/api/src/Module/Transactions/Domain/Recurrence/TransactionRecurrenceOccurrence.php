<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One expected instalment of a recurrence.
 *
 * It snapshots the expected amount, its submitted scale and the tolerance, so a
 * later edit of the recurrence cannot change how an already generated — and
 * possibly already matched — instalment is explained. An occurrence matches at
 * most one transaction, and that pairing is the only thing that makes it
 * `RECEIVED`.
 */
final readonly class TransactionRecurrenceOccurrence
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $recurrenceId,
        public \DateTimeImmutable $expectedOn,
        public AssetAmount $expectedAmount,
        public AssetAmount $amountTolerance,
        public ?string $matchedTransactionId,
        public ?\DateTimeImmutable $matchedAt,
        public OccurrenceStatus $status,
    ) {
        RecurrenceIdentity::identifier($id, 'occurrence identifier');
        RecurrenceIdentity::identifier($recurrenceId, 'occurrence recurrence');
        if (null !== $matchedTransactionId) {
            RecurrenceIdentity::identifier($matchedTransactionId, 'occurrence matched transaction');
        }
        RecurrenceIdentity::expectation($expectedAmount, $amountTolerance);
        $received = OccurrenceStatus::RECEIVED === $status;
        if ($received !== (null !== $matchedTransactionId) || $received !== (null !== $matchedAt)) {
            throw new InvalidRecurrence('An occurrence is received exactly when it names the transaction it matched and when.');
        }
    }
}
