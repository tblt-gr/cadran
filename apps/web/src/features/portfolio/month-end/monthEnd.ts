const MONTH = /^(\d{4})-(0[1-9]|1[0-2])$/;

/** A `YYYY-MM` value, or null when the text is not a calendar month. */
export function parseMonthEnd(value: string | null): string | null {
  return value !== null && MONTH.test(value) ? value : null;
}

/** The last calendar day of a `YYYY-MM` month, by calendar arithmetic only. */
export function lastDayOfMonth(month: string): string {
  const [year, number] = month.split('-').map(Number) as [number, number];
  const day = new Date(Date.UTC(year, number, 0)).getUTCDate();
  return `${month}-${String(day).padStart(2, '0')}`;
}

/** The month name alone, lowercase, for use inside a sentence. */
export function monthEndLabel(month: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(`${month}-01T12:00:00Z`),
  );
}

/** The path that opens the valuation modal of a due month. */
export function monthEndPath(accountId: string, month: string): string {
  return `/portfolios/${accountId}?tab=valuations&monthEnd=${month}`;
}

/** The month name, with its year only when the due months span several years and would be ambiguous. */
export function dueMonthLabel(month: string, dueMonths: readonly string[], locale: string): string {
  const years = new Set(dueMonths.map((due) => due.slice(0, 4)));
  return years.size > 1
    ? `${monthEndLabel(month, locale)} ${month.slice(0, 4)}`
    : monthEndLabel(month, locale);
}
