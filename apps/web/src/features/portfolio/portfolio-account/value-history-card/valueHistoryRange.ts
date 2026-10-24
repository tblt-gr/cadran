import type { ChartPeriod } from '@/lib/chartPeriodRange';

export const HISTORY_PERIODS: readonly ChartPeriod[] = [
  'ALL',
  'LAST_12_MONTHS',
  'LAST_24_MONTHS',
  'YTD',
];

export const DEFAULT_HISTORY_PERIOD: ChartPeriod = 'LAST_24_MONTHS';

const URL_VALUES: Record<ChartPeriod, string> = {
  ALL: 'all',
  LAST_12_MONTHS: '12',
  LAST_24_MONTHS: '24',
  YTD: 'ytd',
};

/** Closed set: a missing or unknown `?history=` value falls back to the 24-month default. */
export function parseHistoryPeriod(raw: string | null): ChartPeriod {
  return HISTORY_PERIODS.find((period) => URL_VALUES[period] === raw) ?? DEFAULT_HISTORY_PERIOD;
}

export function historyUrlValue(period: ChartPeriod): string {
  return URL_VALUES[period];
}
