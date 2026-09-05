/**
 * Dates for the dashboard cards that still show placeholder figures. Real
 * dates go through `lib/decimal`, which formats what the API answered.
 */
function parseIsoDate(value: string) {
  const normalized = value.length === 7 ? `${value}-01` : value;
  return new Date(`${normalized}T12:00:00Z`);
}

function capitalize(value: string, locale: string) {
  return value.charAt(0).toLocaleUpperCase(locale) + value.slice(1);
}

export function formatDemoMonth(value: string, locale: string) {
  return capitalize(
    new Intl.DateTimeFormat(locale, {
      month: 'long',
      timeZone: 'UTC',
      year: 'numeric',
    }).format(parseIsoDate(value)),
    locale,
  );
}
