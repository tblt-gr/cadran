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
