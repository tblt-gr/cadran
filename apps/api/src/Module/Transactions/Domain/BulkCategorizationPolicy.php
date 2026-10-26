<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Categories\Domain\CategoryType;

/**
 * Decides, for one transaction, whether a bulk change of category may apply.
 * The reasons are checked from the most structural to the most incidental, so
 * the one reported is the first thing the user would have to change.
 */
final readonly class BulkCategorizationPolicy
{
    public static function skipReason(
        Transaction $transaction,
        string $targetCategoryId,
        CategoryType $targetType,
        BulkCategorizationFacts $facts,
    ): ?BulkCategorizationSkipReason {
        return match (true) {
            $facts->transferLeg => BulkCategorizationSkipReason::TRANSFER_LEG,
            $facts->refund => BulkCategorizationSkipReason::REFUND,
            $facts->liveRefund => BulkCategorizationSkipReason::HAS_REFUNDS,
            $transaction->state->isTerminal() => BulkCategorizationSkipReason::TERMINAL,
            count($transaction->splits) > 1 => BulkCategorizationSkipReason::SPLIT,
            $facts->periodClosed => BulkCategorizationSkipReason::CLOSED_PERIOD,
            // Same rule as a single edit: an outflow takes an expense category, anything else an income one.
            ($transaction->amount->value->isNegative() ? CategoryType::EXPENSE : CategoryType::INCOME) !== $targetType => BulkCategorizationSkipReason::SIGN_MISMATCH,
            1 === count($transaction->splits) && $transaction->splits[0]->categoryId === $targetCategoryId => BulkCategorizationSkipReason::ALREADY_IN_CATEGORY,
            default => null,
        };
    }
}
