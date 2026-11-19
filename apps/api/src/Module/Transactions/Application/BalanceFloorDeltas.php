<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Application\AccountBalanceDelta;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Expresses a transaction write as the signed changes it brings to day-end balances:
 * the row as it stood is removed, the row as it will stand is added. Only a pending or
 * booked row counts toward a balance.
 */
final class BalanceFloorDeltas
{
    private function __construct()
    {
    }

    /** @return list<AccountBalanceDelta> */
    public static function removal(?Transaction $transaction, string $field = AccountBalanceDelta::FIELD_AMOUNT): array
    {
        if (null === $transaction || !self::counts($transaction)) {
            return [];
        }

        return [new AccountBalanceDelta($transaction->accountId, $transaction->bookedOn, ExactDecimal::negate($transaction->amount->value), $field)];
    }

    /** @return list<AccountBalanceDelta> */
    public static function addition(?Transaction $transaction, string $field = AccountBalanceDelta::FIELD_AMOUNT): array
    {
        if (null === $transaction || !self::counts($transaction)) {
            return [];
        }

        return [new AccountBalanceDelta($transaction->accountId, $transaction->bookedOn, $transaction->amount->value, $field)];
    }

    /** @return list<AccountBalanceDelta> the old row removed and the new one added */
    public static function replacement(?Transaction $before, ?Transaction $after, string $field = AccountBalanceDelta::FIELD_AMOUNT): array
    {
        return [...self::removal($before, $field), ...self::addition($after, $field)];
    }

    private static function counts(Transaction $transaction): bool
    {
        return null === $transaction->voidedAt
            && in_array($transaction->state, [TransactionState::PENDING, TransactionState::BOOKED], true);
    }
}
