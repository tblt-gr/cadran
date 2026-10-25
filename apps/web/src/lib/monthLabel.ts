/** Month label with year (`Septembre 2026`), capitalised like the Budget month tabs. */
export function formatMonthLabel(month: string, locale: string): string {
  const label = new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));
  return label.charAt(0).toLocaleUpperCase(locale) + label.slice(1);
}

/** A January is told by its year, any other month by its name and year (`2026`, `Septembre 2026`). */
export function axisTickLabel(month: string, locale: string): string {
  return month.endsWith('-01') ? month.slice(0, 4) : formatMonthLabel(month, locale);
}
