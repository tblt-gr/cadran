import type { AnnualAggregate, AnnualColumn } from '@cadran/api-client';

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
 * Where a monthly cell's figure comes from: the monthly KPI report for an indicator or a group,
 * the month's budget for a category or an axis, the account detail for an account.
 */
export function annualCellHref(columnId: string, month: string): string {
  const [type, reference = ''] = columnId.split(':', 2);
  switch (type) {
    case 'account':
      return `/accounts/${encodeURIComponent(reference)}`;
    case 'axis':
      return `/budget/${month}?axis=${encodeURIComponent(reference)}`;
    case 'category':
      return `/budget/${month}`;
    default:
      return `/reports?month=${month}`;
  }
}

export function columnGroup(column: Pick<AnnualColumn, 'id'>): string {
  const [prefix] = column.id.split(':');
  return column.id.includes(':') ? prefix! : 'indicator';
}
