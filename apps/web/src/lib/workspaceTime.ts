/**
 * A pure function of an instant and an IANA zone: every caller sources the
 * zone from the workspace's own session payload (`session.data.workspace.timeZone`,
 * read through `useWorkspaceTimeZone()`), never from a constant, so every
 * feature that needs "today" agrees on what day the workspace is living in.
 */
export function workspaceToday(now: Date, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en', {
    day: '2-digit',
    month: '2-digit',
    timeZone,
    year: 'numeric',
  }).formatToParts(now);
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((candidate) => candidate.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

/** The last day (`YYYY-MM-DD`) of the month a `YYYY-MM-DD` day belongs to. */
export function lastDayOfMonth(day: string): string {
  const year = Number(day.slice(0, 4));
  const month = Number(day.slice(5, 7));
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate();

  return `${day.slice(0, 7)}-${String(last).padStart(2, '0')}`;
}

/** A day after `today` and no later than the end of its month: a plannable upcoming date. */
export function isUpcomingDay(day: string, today: string): boolean {
  return day > today && day <= lastDayOfMonth(today);
}
