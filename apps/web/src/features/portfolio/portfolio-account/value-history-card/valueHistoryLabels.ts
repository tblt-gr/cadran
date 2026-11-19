import type { PortfolioValuationHistoryPoint } from '@cadran/api-client';
import type { DecimalAmount } from '@cadran/api-client';
import { formatDecimal } from '@/lib/decimal';
import type { TFunction } from 'i18next';
import { flagShortLabel } from '@/features/portfolio/portfolio-account/overviewLabels';
import { maskAmountText } from '@/lib/amountPrivacy';

/** Why a figure of the point is missing: partial valuation, breakdown not entered, otherwise not computable. */
export function missingReason(t: TFunction, point: PortfolioValuationHistoryPoint): string {
  if (point.completeness === 'PARTIAL') {
    return t('portfolio.account.warning.PARTIAL_VALUATION');
  }

  return point.detail === 'TOTAL'
    ? t('portfolio.account.history.detailMissing')
    : t('states.notCalculable.label');
}

export function detailLabel(
  t: TFunction,
  detail: PortfolioValuationHistoryPoint['detail'],
): string {
  return t(`portfolio.account.history.detail.${detail}`);
}

export function priceQualityLabel(
  t: TFunction,
  quality: PortfolioValuationHistoryPoint['priceQuality'],
): string | null {
  return quality === 'NOT_DETAILED' ? null : t(`portfolio.account.history.quality.${quality}`);
}

/** The warnings of a point as text, partial completeness first; never colour alone. */
export function pointWarnings(t: TFunction, point: PortfolioValuationHistoryPoint): string[] {
  return [
    ...(point.completeness === 'PARTIAL' ? [t('portfolio.account.warning.PARTIAL_VALUATION')] : []),
    ...point.flags.map((flag) => flagShortLabel(t, flag)),
  ];
}

/** Every stored digit is kept: the history shows observed figures, never rounded ones. */
export function exactAmount(amount: DecimalAmount, locale: string): string {
  return maskAmountText(`${formatDecimal(amount.value, locale)}\u00a0${amount.assetCode}`);
}
