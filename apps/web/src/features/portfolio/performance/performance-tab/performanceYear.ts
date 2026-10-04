/** The year of `?year=`, else the year of the Au date. */
export function performanceYear(asOf: string): number {
  const value = new URLSearchParams(window.location.search).get('year');
  return value && /^\d{4}$/.test(value) ? Number(value) : Number(asOf.slice(0, 4));
}
