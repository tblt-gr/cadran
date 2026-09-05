import type { NetWorth } from '@cadran/api-client';

/**
 * The most recent day a contributing account was actually valued, or null
 * when none of them carries a figure. Days are ISO 8601, so ordering them as
 * strings is ordering them as dates.
 */
export function latestValuationDay(netWorth: NetWorth | undefined): string | null {
  if (netWorth === undefined) {
    return null;
  }

  const days = netWorth.contributions
    .map((contribution) => contribution.valuedOn)
    .filter((day): day is string => day !== null)
    .sort();

  return days.at(-1) ?? null;
}
