import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

export function formatAnnualValue(
  value: string,
  kind: 'FLOW' | 'STOCK' | 'RATE',
  assetCode: string | null,
  locale: string,
): string {
  return kind === 'RATE' || assetCode === null
    ? formatRatioPercentage(value, locale)
    : formatAmount(value, assetCode, locale);
}
