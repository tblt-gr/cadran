/** Calendar-day arithmetic on ISO dates, done in UTC so no time zone can shift a day. */
function isoDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function startOfYear(today: string): string {
  return `${today.slice(0, 4)}-01-01`;
}

/** The 12 months ending today, inclusive: one year back, plus one day. */
export function lastTwelveMonths(today: string): string {
  const [year, month, day] = today.split('-').map(Number) as [number, number, number];
  return isoDay(new Date(Date.UTC(year - 1, month - 1, day + 1)));
}
