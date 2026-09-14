<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A confirmed rhythm: the schedule a user accepted, with the amount and
 * tolerance a real movement is later recognised against.
 *
 * It is a forecast and never a booked fact. A recurrence creates, edits or
 * categorises no transaction, and its expected occurrences live in their own
 * table, outside every total, listing and export.
 */
final readonly class TransactionRecurrence
{
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_COUNTERPARTY_LENGTH = 80;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public string $label,
        public ?string $counterparty,
        public AssetAmount $expectedAmount,
        public AssetAmount $amountTolerance,
        public RecurrenceIntervalKind $intervalKind,
        public int $dayOfPeriod,
        public \DateTimeImmutable $nextExpectedOn,
        public \DateTimeImmutable $confirmedAt,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $archivedAt,
    ) {
        RecurrenceIdentity::identifier($id, 'identifier');
        RecurrenceIdentity::identifier($accountId, 'account');
        RecurrenceIdentity::text($label, self::MAX_LABEL_LENGTH, 'label');
        if (null !== $counterparty) {
            RecurrenceIdentity::text($counterparty, self::MAX_COUNTERPARTY_LENGTH, 'counterparty');
        }
        RecurrenceIdentity::expectation($expectedAmount, $amountTolerance);
        if (!$intervalKind->accepts($dayOfPeriod)) {
            throw new InvalidRecurrence('A weekly recurrence anchors on an ISO weekday, any other on a day of month.');
        }
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new InvalidRecurrence('A recurrence version and timestamps must be ordered.');
        }
    }
}
