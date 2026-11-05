import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';

/**
 * The transactions list of one budget panel over one month: the natures and category type that
 * feed the panel's total, so the list holds exactly the transactions behind it.
 */
export function monthTransactionsHref(
  kind: LedgerKind,
  month: string,
  axis: BudgetAxis | null,
): string {
  const params = new URLSearchParams();

  if (kind === 'income') {
    params.append('nature', 'INCOME');
    params.append('categoryType', 'INCOME');
  } else if (kind === 'expense') {
    for (const nature of ['EXPENSE', 'FEE', 'REFUND']) params.append('nature', nature);
    params.append('categoryType', 'EXPENSE');
    if (axis) params.append('axis', axis);
  } else {
    params.append('nature', 'TRANSFER');
  }
  params.append('period', 'month');
  params.append('month', month);

  return `/transactions?${params.toString()}`;
}
