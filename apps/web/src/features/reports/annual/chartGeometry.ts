import type { AnnualFlowMonth } from '@cadran/api-client';
import { compareUnsignedDecimals, decimalRatioForGeometry } from '@/lib/decimal';

function magnitude(value: string): string {
  return value.startsWith('-') ? value.slice(1) : value;
}

/**
 * Scales exact decimals to [-1, 1] for drawing only. Every displayed figure stays the backend's
 * exact string; the number returned here is a bounded geometry coordinate.
 */
export function scaleForGeometry(values: (string | null)[]): (number | null)[] {
  let largest = '0';
  for (const value of values) {
    if (value !== null && compareUnsignedDecimals(magnitude(value), largest) > 0) {
      largest = magnitude(value);
    }
  }
  return values.map((value) => {
    if (value === null || compareUnsignedDecimals(largest, '0') === 0)
      return value === null ? null : 0;
    const ratio = decimalRatioForGeometry(magnitude(value), largest);
    return value.startsWith('-') ? -ratio : ratio;
  });
}

export function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;
}

export const FLOW_SERIES_IDS = ['cashIncome', 'budgetExpenses', 'budgetSurplus'] as const;

/** One shared scale for all flow series, so bar heights stay comparable across series. */
export function flowChartData(months: AnnualFlowMonth[]) {
  const scaled = scaleForGeometry(
    months.flatMap((month) => FLOW_SERIES_IDS.map((id) => month[id].value)),
  );
  return months.map((month, index) => ({
    month: month.month.slice(5),
    ...Object.fromEntries(
      FLOW_SERIES_IDS.map((id, s) => [id, scaled[index * FLOW_SERIES_IDS.length + s]]),
    ),
  }));
}
