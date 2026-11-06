<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/**
 * Decides, for one transaction that passed {@see BulkEditPolicy::rowReason()}, whether it may change account
 * and/or booked date. The first matching reason wins and refuses the whole row, so a move never half applies.
 */
final readonly class BulkMovePolicy
{
    /**
     * @param ?BulkMoveTarget     $target  the requested account, null when only the date changes
     * @param BulkMoveTarget      $current the account the row sits on today
     * @param ?\DateTimeImmutable $newDate the requested booked day, null when only the account changes
     * @param \DateTimeImmutable  $today   the workspace calendar day
     */
    public static function reason(
        Transaction $transaction,
        BulkCategorizationFacts $facts,
        ?BulkMoveTarget $target,
        BulkMoveTarget $current,
        ?\DateTimeImmutable $newDate,
        \DateTimeImmutable $today,
    ): ?BulkEditSkipReason {
        $effectiveDate = $newDate ?? $transaction->bookedOn;
        $destination = $target ?? $current;

        return match (true) {
            // A date that does not change enters no new month.
            $facts->targetPeriodClosed && null !== $newDate && $newDate != $transaction->bookedOn => BulkEditSkipReason::CLOSED_PERIOD,
            $facts->portfolioLinked => BulkEditSkipReason::PORTFOLIO_LINKED,
            null !== $transaction->reviewReason => BulkEditSkipReason::UNDER_REVIEW,
            $facts->reconciled => BulkEditSkipReason::RECONCILED,
            null !== $target && $target->portfolioValued => BulkEditSkipReason::PORTFOLIO_ACCOUNT,
            null !== $target && $target->assetCode !== $transaction->amount->asset->toString() => BulkEditSkipReason::ASSET_MISMATCH,
            null !== $target && $target->accountId === $transaction->accountId => BulkEditSkipReason::ALREADY_ON_ACCOUNT,
            !$destination->isOpenOn($effectiveDate) => BulkEditSkipReason::ACCOUNT_NOT_OPEN,
            null !== $newDate && TransactionState::BOOKED === $transaction->state && $newDate > $today => BulkEditSkipReason::FUTURE_BOOKED,
            // A refund is never dated before the expense it reduces, as in a single edit.
            null !== $newDate && null !== $facts->originalBookedOn && $newDate < $facts->originalBookedOn => BulkEditSkipReason::REFUND,
            default => null,
        };
    }
}
