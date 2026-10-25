import type { MultiYearDisplayMode } from '@/features/reports/multi-year/YearRangeSelector';
export type YearView = 'annual' | 'multi';

export interface YearViewState {
  view: YearView;
  display: MultiYearDisplayMode;
  /** Requested multi-year range; null lets the backend apply its own default bounds. */
  range: { from: number; to: number } | null;
}

const YEAR_PATTERN = /^\d{4}$/;

/** Reads the view state of `/budget/YYYY` from its query string; anything unusable falls back to the compact annual report. */
export function readYearView(search: string): YearViewState {
  const params = new URLSearchParams(search);
  if (params.get('view') !== 'multi') return { view: 'annual', display: 'compact', range: null };
  const from = params.get('from');
  const to = params.get('to');
  const range =
    from !== null && to !== null && YEAR_PATTERN.test(from) && YEAR_PATTERN.test(to)
      ? { from: Number(from), to: Number(to) }
      : null;

  return {
    view: 'multi',
    display: params.get('display') === 'detailed' ? 'detailed' : 'compact',
    range,
  };
}

/** Query string (with its leading `?`, or empty) of a view state; the annual view has none. */
export function writeYearView(state: YearViewState): string {
  if (state.view === 'annual') return '';
  const params = new URLSearchParams({ view: 'multi' });
  if (state.display === 'detailed') params.set('display', 'detailed');
  if (state.range !== null) {
    params.set('from', String(state.range.from));
    params.set('to', String(state.range.to));
  }

  return `?${params.toString()}`;
}
