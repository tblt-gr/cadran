/** Month label with year (`Septembre 2026`), capitalised like the Budget month tabs. */
export function formatMonthLabel(month: string, locale: string): string {
  const label = new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));
  return label.charAt(0).toLocaleUpperCase(locale) + label.slice(1);
}
