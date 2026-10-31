/** Chart presets shared by every configurable period control, in their canonical display order. */
export type ChartPeriod = 'ALL' | 'LAST_12_MONTHS' | 'LAST_24_MONTHS' | 'YTD';

export const CHART_PERIOD_ORDER: readonly ChartPeriod[] = [
  'ALL',
  'LAST_24_MONTHS',
  'LAST_12_MONTHS',
  'YTD',
];

const ROLLING_MONTHS = { LAST_12_MONTHS: 12, LAST_24_MONTHS: 24 } as const;

/** Keeps the canonical relative order and drops duplicates, whatever order the caller declares. */
export function sortChartPeriods(periods: readonly ChartPeriod[]): ChartPeriod[] {
  return CHART_PERIOD_ORDER.filter((period) => periods.includes(period));
}

/** `day` (YYYY-MM-DD) minus whole calendar months, clamped to the last day of a shorter month. */
export function subtractCalendarMonths(day: string, months: number): string {
  const [year, month, dayOfMonth] = day.split('-').map(Number);
  const index = year * 12 + (month - 1) - months;
  const targetYear = Math.floor(index / 12);
  const targetMonth = (index % 12) + 1;
  const lastDay = new Date(Date.UTC(targetYear, targetMonth, 0)).getUTCDate();
  const pad = (value: number, size = 2) => String(value).padStart(size, '0');
  return `${pad(targetYear, 4)}-${pad(targetMonth)}-${pad(Math.min(dayOfMonth, lastDay))}`;
}

/** Start day of a preset ending on `referenceDay`; undefined for ALL, which each API resolves itself. */
export function chartPeriodFrom(period: ChartPeriod, referenceDay: string): string | undefined {
  if (period === 'ALL') return undefined;
  // Closing balance of the previous December 31, so January movements are counted.
  if (period === 'YTD') return `${Number(referenceDay.slice(0, 4)) - 1}-12-31`;
  return subtractCalendarMonths(referenceDay, ROLLING_MONTHS[period]);
}
