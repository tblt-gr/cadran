import type { AnnualMonthState } from '@cadran/api-client';

/** French long month name for a `YYYY-MM` key, without building a Date from a local zone. */
export function monthLabel(month: string, locale: string): string {
  const [year, index] = month.split('-').map(Number);
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(Date.UTC(year!, index! - 1, 1)),
  );
}

/** Short month with its year (`déc. 2025`), for a point outside the report's own year. */
export function monthYearLabel(month: string, locale: string): string {
  const [year, index] = month.split('-').map(Number);
  return new Intl.DateTimeFormat(locale, {
    month: 'short',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(Date.UTC(year!, index! - 1, 1)));
}

export function isEmptyState(state: AnnualMonthState): boolean {
  return state === 'FUTURE' || state === 'NO_DATA';
}
