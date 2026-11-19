import type { Account } from '@cadran/api-client';
import { compareDecimals, subtractDecimals } from '@/lib/decimal';

/**
 * Whether taking `outflow` (a positive magnitude) out of `balance` leaves the account below zero.
 * Exact decimals only. A zero or negative outflow is a deposit, which never deepens a shortfall.
 */
export function wouldGoNegative(balance: string, outflow: string): boolean {
  return (
    compareDecimals(outflow, '0') > 0 &&
    compareDecimals(subtractDecimals(balance, outflow), '0') < 0
  );
}

/** The accounts the server refuses to overdraw: current, savings and cash accounts valued by their transactions. */
export function isBalanceGuarded(account: Account | undefined): boolean {
  return (
    account !== undefined &&
    account.status === 'ACTIVE' &&
    account.valuationMode === 'TRANSACTIONS' &&
    (account.kind === 'CURRENT' || account.kind === 'SAVINGS' || account.kind === 'CASH')
  );
}
