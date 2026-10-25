import type { AnnualAggregate } from '@cadran/api-client';
import { monthHref } from '@/features/budget/monthly-budget/budgetPeriod';

export const MAX_ANNUAL_COLUMNS = 40;

export const INDICATOR_COLUMN_IDS = [
  'cashIncome',
  'nonCashBenefits',
  'budgetExpenses',
  'benefitSpending',
  'uncategorizedExpenses',
  'budgetSurplus',
  'cashSavingsRate',
  'savingsInflows',
  'savingsWithdrawals',
  'netSavingsTransfers',
  'netSavingsRate',
  'netWorthDelta',
  'endNetWorth',
] as const;

export const AXIS_IDS = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
] as const;

export type SummaryRowId =
  'total' | 'average' | 'median' | 'minimum' | 'maximum' | 'previousYearAverage';

export const SUMMARY_ROW_IDS: SummaryRowId[] = [
  'total',
  'average',
  'median',
  'minimum',
  'maximum',
  'previousYearAverage',
];

export interface SummaryFigure {
  reason: string | null;
  value: string | null;
  /** Month an extreme falls in, when the figure is a minimum or a maximum. */
  month: string | null;
}

export function summaryFigure(aggregate: AnnualAggregate, row: SummaryRowId): SummaryFigure {
  switch (row) {
    case 'total':
      return { value: aggregate.total, reason: aggregate.totalReason, month: null };
    case 'average':
      return { value: aggregate.average, reason: aggregate.averageReason, month: null };
    case 'median':
      return { value: aggregate.median, reason: aggregate.medianReason, month: null };
    case 'minimum':
    case 'maximum': {
      const extreme = row === 'minimum' ? aggregate.minimum : aggregate.maximum;
      return {
        value: extreme?.value ?? null,
        reason: extreme === null ? aggregate.extremesReason : null,
        month: extreme?.month ?? null,
      };
    }
    case 'previousYearAverage':
      return {
        value: aggregate.previousYearAverage,
        reason: aggregate.previousYearAverageReason,
        month: null,
      };
  }
}

/**
 * Where a monthly cell's figure comes from: the month's budget for an indicator, a group, a category or an axis, the account detail for an account.
 */
export function annualCellHref(columnId: string, month: string): string {
  const [type, reference = ''] = columnId.split(':', 2);
  switch (type) {
    case 'account':
      return `/accounts/${encodeURIComponent(reference)}`;
    case 'axis':
      return `${monthHref(month, null)}?axis=${encodeURIComponent(reference)}`;
    default:
      return monthHref(month, null);
  }
}

export type ColumnGroup = 'indicator' | 'axis' | 'category' | 'account' | 'group';

/** The family a column id belongs to: its prefix (`axis:`, `category:`, ...), else an indicator. */
export function columnGroup(columnId: string): ColumnGroup {
  const prefix = columnId.split(':', 1)[0];
  return prefix === 'axis' || prefix === 'category' || prefix === 'account' || prefix === 'group'
    ? prefix
    : 'indicator';
}

/** Ids of the columns that open a new family, to draw a vertical separator before them. */
export function groupStartIds(columns: readonly { id: string }[]): Set<string> {
  const starts = new Set<string>();
  columns.forEach((column, index) => {
    if (index > 0 && columnGroup(column.id) !== columnGroup(columns[index - 1]!.id)) {
      starts.add(column.id);
    }
  });
  return starts;
}

/**
 * Drops the months before the first one with data: a workspace that starts in September has no
 * January to August to show. Nothing is dropped when no month has data, and the months after the
 * first data month are always kept, whatever their state.
 */
export function withoutLeadingEmptyMonths<T extends { state: string }>(rows: readonly T[]): T[] {
  const first = rows.findIndex((row) => row.state !== 'NO_DATA');
  return first <= 0 ? [...rows] : rows.slice(first);
}

/** First month of a report that holds data, or null when none does. */
export function firstDataMonth(rows: readonly { month: string; state: string }[]): string | null {
  return rows.find((row) => row.state !== 'NO_DATA')?.month ?? null;
}

/** Keeps the months of a chart series from the first month with data on (`YYYY-MM` sorts as text). */
export function fromFirstDataMonth<T extends { month: string }>(
  months: readonly T[],
  first: string | null,
): T[] {
  return first === null ? [...months] : months.filter((entry) => entry.month >= first);
}
