import {
  monthHref,
  readAxis,
  type BudgetAxis,
} from '@/features/budget/monthly-budget/budgetPeriod';
import {
  DEFAULT_FILTERS,
  encodeFilters,
  type TransactionFilterState,
} from '@/features/transactions/transaction-filters/filterState';

export const BUDGET_CONTEXT_PARAMS = ['budgetMonth', 'budgetAxis'] as const;
const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

/** The return context is explicit URL data, independent of the register's selection and filters. */
export function budgetRegisterHref(
  month: string,
  axis: BudgetAxis | null,
  filters: Partial<TransactionFilterState> = {},
) {
  const params = encodeFilters({ ...DEFAULT_FILTERS, period: 'month', month, ...filters });
  appendBudgetContext(params, month, axis);
  return `/transactions?${params.toString()}`;
}

export function budgetReturnHref(
  search: string,
  selectedMonth: string | null,
  fallbackMonth: string,
) {
  const params = new URLSearchParams(search);
  const context = params.get('budgetMonth') ?? '';
  const month = selectedMonth ?? (MONTH.test(context) ? context : fallbackMonth);
  return monthHref(month, readAxis(`?axis=${params.get('budgetAxis') ?? ''}`));
}

/** Context does not narrow the movements: only explicit register filters do. */
export function appendBudgetContext(
  params: URLSearchParams,
  month: string,
  axis: BudgetAxis | null,
) {
  params.set('budgetMonth', month);
  if (axis) params.set('budgetAxis', axis);
}

/** The budget month and axis a tab page was opened from, when its URL carries them. */
export function readBudgetContext(search: string): {
  month: string | null;
  axis: BudgetAxis | null;
} {
  const params = new URLSearchParams(search);
  const month = params.get('budgetMonth') ?? '';
  return {
    month: MONTH.test(month) ? month : null,
    axis: readAxis(`?axis=${params.get('budgetAxis') ?? ''}`),
  };
}

/** A sibling tab keeps the budget context so the way back lands on the same month. */
export function budgetContextHref(path: string, month: string | null, axis: BudgetAxis | null) {
  if (month === null) return path;
  const params = new URLSearchParams();
  appendBudgetContext(params, month, axis);
  return `${path}?${params.toString()}`;
}
