/** Brings a `YYYY-MM` month inside `[earliest, latest]`; the one place month bounds are applied. */
export function clampMonth(month: string, earliest: string, latest: string): string {
  if (month > latest) return latest;
  return month < earliest ? earliest : month;
}

/** True only for a known bound: an unknown or absent first data month never rejects a month. */
export function isBeforeFirstDataMonth(month: string, firstDataMonth: string | null): boolean {
  return firstDataMonth !== null && month < firstDataMonth;
}
