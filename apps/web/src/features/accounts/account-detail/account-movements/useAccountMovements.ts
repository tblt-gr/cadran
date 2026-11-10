import {
  isImpossibleCombination,
  toListTransactionsQuery,
  type TransactionFilterState,
} from '@/features/transactions/transaction-filters/filterState';
import { useTransactionPages } from '@/features/transactions/transaction-list/useTransactionPages';

/** Account scope is fixed independently of the user's filter choices. */
export function useAccountMovements(accountId: string, selection: TransactionFilterState) {
  const impossible = isImpossibleCombination(selection);
  const bulkQuery = {
    ...toListTransactionsQuery({ ...selection, accountId: [] }, new Date()),
    accountId: [accountId],
  };
  return { ...useTransactionPages(bulkQuery, impossible, true), bulkQuery, impossible };
}
