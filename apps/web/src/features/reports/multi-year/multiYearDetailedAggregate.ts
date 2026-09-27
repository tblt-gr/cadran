import type { MultiYearDetailedAggregate } from '@cadran/api-client';

export type MultiYearDetailedAggregateRowId =
  'total' | 'average' | 'median' | 'minimum' | 'maximum';

export const MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS: MultiYearDetailedAggregateRowId[] = [
  'total',
  'average',
  'median',
  'minimum',
  'maximum',
];

export interface MultiYearDetailedAggregateFigure {
  reason: string | null;
  value: string | null;
  /** Month an extreme falls in, when the figure is a minimum or a maximum. */
  month: string | null;
}

/**
 * Reads one row's figure off the server-computed whole-detailed-period aggregate; no client-side
 * computation. Mirrors the per-year `summaryFigure` in `annualColumns.ts`.
 */
export function multiYearDetailedAggregateFigure(
  aggregate: MultiYearDetailedAggregate,
  row: MultiYearDetailedAggregateRowId,
): MultiYearDetailedAggregateFigure {
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
  }
}
