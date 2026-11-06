<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Categories\Domain\CategoryType;

/**
 * Decides, for one transaction, what a bulk edit may apply. A row-level reason
 * refuses the whole row, exactly where a single edit would be refused; a
 * category reason only keeps the category from changing.
 */
final readonly class BulkEditPolicy
{
    /**
     * @param ?string $label the trimmed label requested for this row, null when the label is not edited
     */
    public static function rowReason(Transaction $transaction, BulkCategorizationFacts $facts, ?string $label): ?BulkEditSkipReason
    {
        return match (true) {
            $facts->transferLeg => BulkEditSkipReason::TRANSFER_LEG,
            $facts->liveRefund => BulkEditSkipReason::HAS_REFUNDS,
            $transaction->state->isTerminal() => BulkEditSkipReason::TERMINAL,
            $facts->periodClosed => BulkEditSkipReason::CLOSED_PERIOD,
            // A label of our own is its only label: it cannot be emptied, an external one falls back to the bank label.
            '' === $label && !self::isExternal($transaction) => BulkEditSkipReason::LABEL_REQUIRED,
            default => null,
        };
    }

    public static function categoryReason(
        Transaction $transaction,
        string $targetCategoryId,
        CategoryType $targetType,
        BulkCategorizationFacts $facts,
    ): ?BulkEditSkipReason {
        $reason = BulkCategorizationPolicy::skipReason($transaction, $targetCategoryId, $targetType, $facts);

        return null === $reason ? null : BulkEditSkipReason::from($reason->value);
    }

    /** An externally sourced row keeps its bank label; the user's label is a separate attribute. */
    public static function isExternal(Transaction $transaction): bool
    {
        return in_array($transaction->source, [TransactionSource::IMPORT, TransactionSource::PROVIDER], true);
    }
}
