<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\Transaction;

/**
 * A confirmed rhythm: the schedule a user accepted, with the amount and
 * tolerance a real movement is later recognised against.
 *
 * It is a forecast and never a booked fact, unless `autoCreate` is on: then
 * the application creates its transactions for the current month only, and the
 * category, note and payment method below are what each one inherits. Its
 * expected occurrences live in their own table, outside every total, listing
 * and export.
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
        public bool $autoCreate = false,
        public ?string $categoryId = null,
        public ?string $note = null,
        public ?PaymentMethod $paymentMethod = null,
    ) {
        RecurrenceIdentity::identifier($id, 'identifier');
        RecurrenceIdentity::identifier($accountId, 'account');
        RecurrenceIdentity::text($label, self::MAX_LABEL_LENGTH, 'label');
        if (null !== $counterparty) {
            RecurrenceIdentity::text($counterparty, self::MAX_COUNTERPARTY_LENGTH, 'counterparty');
        }
        RecurrenceIdentity::expectation($expectedAmount, $amountTolerance);
        if (null !== $categoryId) {
            RecurrenceIdentity::identifier($categoryId, 'category');
        }
        if (null !== $note) {
            RecurrenceIdentity::text($note, Transaction::MAX_NOTE_LENGTH, 'note');
        }
        if (!$intervalKind->accepts($dayOfPeriod)) {
            throw new InvalidRecurrence('A weekly recurrence anchors on an ISO weekday, any other on a day of month.');
        }
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new InvalidRecurrence('A recurrence version and timestamps must be ordered.');
        }
    }
}
