/**
 * Renders a `YYYY-MM` calendar month in running text ("septembre 2026"), read at
 * midday UTC so the viewer's timezone never shifts it into the previous month.
 */
export function formatGoalMonth(month: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));
}
