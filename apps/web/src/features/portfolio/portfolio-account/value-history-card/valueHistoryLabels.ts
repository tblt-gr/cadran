import type { PortfolioValuationHistoryPoint } from '@cadran/api-client';
import type { DecimalAmount } from '@cadran/api-client';
import { formatDecimal } from '@/lib/decimal';
import { flagLabel } from '@/features/portfolio/valuations/valuationLabels';

/** Why a figure of the point is missing: partial valuation, breakdown not entered, otherwise not computable. */
export function missingReason(point: PortfolioValuationHistoryPoint): string {
  if (point.completeness === 'PARTIAL') {
    return 'Valorisation partielle';
  }

  return point.detail === 'TOTAL' ? 'Détail non saisi' : 'Non calculable';
}

/** The warnings of a point as text, partial completeness first; never colour alone. */
export function pointWarnings(point: PortfolioValuationHistoryPoint): string[] {
  return [
    ...(point.completeness === 'PARTIAL' ? ['Valorisation partielle'] : []),
    ...point.flags.map((flag) => flagLabel[flag]),
  ];
}

/** Every stored digit is kept: the history shows observed figures, never rounded ones. */
export function exactAmount(amount: DecimalAmount, locale: string): string {
  return `${formatDecimal(amount.value, locale)}\u00a0${amount.assetCode}`;
}
