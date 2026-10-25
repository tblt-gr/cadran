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
): string | null {
  if (categoryIds.length === 0 || categoryIds.length > MAX_CATEGORY_FILTER) return null;
  const params = encodeFilters({
    ...DEFAULT_FILTERS,
    axis: axis ? [axis] : [],
    categoryId: [...categoryIds],
    from: period.from,
    period: 'custom',
    to: period.to,
  });

  return `/transactions?${params.toString()}`;
}
