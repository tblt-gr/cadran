import type { TFunction } from 'i18next';

/** `2026-03-31` as `31/03`. */
const dayAndMonth = (date: string) => `${date.slice(8, 10)}/${date.slice(5, 7)}`;

export const emptyMonthKey = {
  BEFORE_OPENING: 'portfolio.performance.months.beforeOpening',
  FUTURE: 'portfolio.performance.months.future',
} as const;

/** `2026-03` as `mars`, read at midday UTC so a timezone never shifts the month. */
export function monthName(month: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(`${month}-15T12:00:00Z`),
  );
}

/** The exact anchors of a month, for instance `Du 28/02 au 31/03`. */
export function anchorsTitle(
  from: string | null,
  to: string | null,
  t: TFunction,
): string | undefined {
  return from && to
    ? t('portfolio.performance.months.anchors', { from: dayAndMonth(from), to: dayAndMonth(to) })
    : undefined;
}
