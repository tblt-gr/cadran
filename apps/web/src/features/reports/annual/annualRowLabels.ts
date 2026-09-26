import type { AnnualMonthState } from '@cadran/api-client';

/** French long month name for a `YYYY-MM` key, without building a Date from a local zone. */
export function monthLabel(month: string, locale: string): string {
  const [year, index] = month.split('-').map(Number);
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(Date.UTC(year!, index! - 1, 1)),
  );
}

export function isEmptyState(state: AnnualMonthState): boolean {
  return state === 'FUTURE' || state === 'NO_DATA';
}
