import type { Account, Transaction } from '@cadran/api-client';
import { createContext } from 'react';
import { compareDecimals } from '@/lib/decimal';

export type TransactionAction = 'inspect' | 'edit' | 'duplicate' | 'void' | 'refund' | 'reconcile';
export interface TransactionActionsContextValue {
  accounts: Account[];
  closedMonths: ReadonlySet<string>;
  duplicatingIds: ReadonlySet<string>;
  open: (action: TransactionAction, transaction: Transaction) => void;
}
export const TransactionActionsContext = createContext<TransactionActionsContextValue | null>(null);

/** Shared by both entry points; the server remains authoritative on every write. */
export function transactionEligibility(
  transaction: Transaction,
  closed: boolean,
  account?: Account,
) {
  const terminal = transaction.state === 'VOIDED' || transaction.state === 'REJECTED';
  const portfolio = account?.valuationMode === 'PORTFOLIO' && transaction.transferId === null;
  const locked = closed || terminal || portfolio;
  const settled =
    transaction.refundedAmount !== null &&
    compareDecimals(transaction.refundedAmount.value, transaction.amount.value.replace(/^-/, '')) >=
      0;
  return {
    edit: !locked && transaction.nature !== 'REFUND',
    duplicate: !portfolio && transaction.transferId === null && transaction.nature !== 'REFUND',
    void: !locked,
    refund:
      !locked &&
      transaction.state === 'BOOKED' &&
      transaction.transferId === null &&
      !settled &&
      (transaction.nature === 'EXPENSE' || transaction.nature === 'FEE'),
    reconcile:
      !locked &&
      transaction.state === 'PENDING' &&
      transaction.reviewReason !== null &&
      transaction.transferId === null,
    reason: closed
      ? ('closed' as const)
      : portfolio
        ? ('portfolio' as const)
        : terminal
          ? ('terminal' as const)
          : null,
  };
}
