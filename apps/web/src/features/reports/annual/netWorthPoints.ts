import type { AnnualNetWorthPoint, AnnualNetWorthSeries } from '@cadran/api-client';

/** One net worth point; the opening is December of the previous year, labelled with its year. */
export interface NetWorthPoint {
  isOpening: boolean;
  point: AnnualNetWorthPoint;
}

/** The series in drawing order: the opening first when it exists, then the months as given. */
export function netWorthPoints(
  series: Pick<AnnualNetWorthSeries, 'months' | 'opening'>,
): NetWorthPoint[] {
  const months = series.months.map((point) => ({ isOpening: false, point }));

  return series.opening === null ? months : [{ isOpening: true, point: series.opening }, ...months];
}
