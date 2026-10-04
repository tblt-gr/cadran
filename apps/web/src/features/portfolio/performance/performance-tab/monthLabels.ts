/** `2026-03-31` as `31/03`. */
const dayAndMonth = (date: string) => `${date.slice(8, 10)}/${date.slice(5, 7)}`;

export const emptyMonthLabel = {
  BEFORE_OPENING: "Avant l'ouverture",
  FUTURE: 'À venir',
} as const;

/** `2026-03` as `mars`, read at midday UTC so a timezone never shifts the month. */
export function monthName(month: string): string {
  return new Intl.DateTimeFormat('fr', { month: 'long', timeZone: 'UTC' }).format(
    new Date(`${month}-15T12:00:00Z`),
  );
}

/** `2026-03` as `mars 2026`. */
export function monthAndYear(month: string): string {
  return `${monthName(month)} ${month.slice(0, 4)}`;
}

/** The exact anchors of a month, for instance `Du 28/02 au 31/03`. */
export function anchorsTitle(from: string | null, to: string | null): string | undefined {
  return from && to ? `Du ${dayAndMonth(from)} au ${dayAndMonth(to)}` : undefined;
}
