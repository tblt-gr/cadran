import { formatPercentDecimal, isZeroDecimal } from '@/lib/decimal';
import { ratioToPercent } from '@/lib/formatRatioPercentage';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';

/**
 * A signed change, e.g. "+2 000,00 €" or "-150,00 €". Zero never carries a
 * sign, matching the shared decimal formatters' negative-zero rule. Used for a
 * FLOW/STOCK column's own change, and for its `changeRatio` (kind `'RATE'`,
 * `assetCode: null`), which is a relative growth percentage.
 */
export function formatSignedChange(
  value: string,
  kind: 'FLOW' | 'STOCK' | 'RATE',
  assetCode: string | null,
  locale: string,
): string {
  const formatted = formatAnnualValue(value, kind, assetCode, locale);
  if (isZeroDecimal(value) || value.startsWith('-')) return formatted;

  return `+${formatted}`;
}

/**
 * A RATE column's own `change` is already expressed in ratio units (0.05 = 5
 * points), never a percentage of a percentage: it is shown in "points", not
 * with a "%" sign, so it is never mistaken for `changeRatio`'s relative growth.
 */
export function formatSignedRatioPoints(value: string, locale: string): string {
  const points = `${formatPercentDecimal(ratioToPercent(value), locale)} points`;
  if (isZeroDecimal(value) || value.startsWith('-')) return points;

  return `+${points}`;
}
