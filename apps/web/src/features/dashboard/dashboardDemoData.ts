/**
 * Placeholder figures for the dashboard cards that have no backend yet.
 *
 * Income and spending arrive with the transaction ledger. Net worth, its
 * history and its allocation are read from the API and are deliberately
 * absent from here: a demonstration figure beside a real one would be
 * indistinguishable from it.
 */
export const dashboardDemoData: {
  currentPeriod: string;
  incomeOperationCount: number;
  incomeValue: string;
  spendingOperationCount: number;
  spendingValue: string;
} = {
  currentPeriod: '2026-03',
  incomeOperationCount: 3,
  incomeValue: '4 320,00 €',
  spendingOperationCount: 12,
  spendingValue: '−2 860,00 €',
};
