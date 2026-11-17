import { appendBudgetContext } from '@/features/budget/budget-navigation/budgetNavigation';
import {
  DEFAULT_FILTERS,
  encodeFilters,
} from '@/features/transactions/transaction-filters/filterState';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';

/** The transactions API accepts at most this many categories in one filter. */
const MAX_CATEGORY_FILTER = 20;

/**
 * The transactions list filtered on the categories of one slice, over the month and, when the
 * budget is read through an analytic axis, on that axis. Null when the slice groups more
 * categories than a filter can carry: a partial list would not match the slice.
 */
export function expenseTransactionsHref(
  categoryIds: readonly string[],
  period: { from: string; to: string },
  axis: BudgetAxis | null,
  /** Also keeps the transactions of the sub-categories: the figure of a parent covers them. */
  includeDescendants = false,
): string | null {
  if (categoryIds.length === 0 || categoryIds.length > MAX_CATEGORY_FILTER) return null;
  const params = encodeFilters({
    ...DEFAULT_FILTERS,
    axis: axis ? [axis] : [],
    categoryId: [...categoryIds],
    includeDescendants,
    from: period.from,
    period: 'custom',
    to: period.to,
  });

  if (period.from.slice(0, 7) === period.to.slice(0, 7))
    appendBudgetContext(params, period.from.slice(0, 7), axis);

  return `/transactions?${params.toString()}`;
}

/** First and last day of a `YYYY-MM` month, as the transactions filter reads them. */
export function monthBounds(month: string): { from: string; to: string } {
  const [year, number] = month.split('-').map(Number) as [number, number];
  const last = new Date(Date.UTC(year, number, 0)).getUTCDate();
  return { from: `${month}-01`, to: `${month}-${String(last).padStart(2, '0')}` };
}

/** The transactions list filtered on one analytic axis over a month. */
export function axisTransactionsHref(
  axis: NonNullable<BudgetAxis>,
  period: { from: string; to: string },
): string {
  const params = encodeFilters({
    ...DEFAULT_FILTERS,
    axis: [axis],
    from: period.from,
    period: 'custom',
    to: period.to,
  });
  appendBudgetContext(params, period.from.slice(0, 7), axis);

  return `/transactions?${params.toString()}`;
}
