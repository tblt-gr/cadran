import type { MultiYearSummaryCell } from '@cadran/api-client';

export type MultiYearSummaryRowId = 'average' | 'minimum' | 'maximum';

export const MULTI_YEAR_SUMMARY_ROW_IDS: MultiYearSummaryRowId[] = [
  'average',
  'minimum',
  'maximum',
];

export interface MultiYearSummaryFigure {
  reason: string | null;
  value: string | null;
  year: number | null;
}

/**
 * The API carries a single `averageReason` for the whole summary cell: minimum
 * and maximum come from the same `COMPLETE`-year population, so a null extreme
 * shares that reason (`EMPTY_POPULATION` or `MIXED_METRIC_POLICIES`).
 */
export function multiYearSummaryFigure(
  cell: MultiYearSummaryCell,
  row: MultiYearSummaryRowId,
): MultiYearSummaryFigure {
  if (row === 'average') {
    return { value: cell.average, reason: cell.averageReason, year: null };
  }
  const extreme = row === 'minimum' ? cell.minimum : cell.maximum;

  return {
    value: extreme?.value ?? null,
    reason: extreme === null ? cell.averageReason : null,
    year: extreme?.year ?? null,
  };
}
