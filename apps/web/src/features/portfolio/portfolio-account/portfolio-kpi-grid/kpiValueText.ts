import { AMOUNT_MASK, areAmountsHidden } from '@/lib/amountPrivacy';
import type { PortfolioOverviewSummary } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import type { KpiCode } from '@/features/portfolio/portfolio-account/overviewLabels';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

/** One indicator of the summary in words, as the grid shows it and its explanation repeats it. */
export function kpiValueText(
  summary: PortfolioOverviewSummary,
  code: KpiCode,
  t: TFunction,
  locale: string,
): string {
  const notCalculable = t('states.notCalculable.label');
  const money = (value: string | null) =>
    value === null ? notCalculable : formatAmount(value, summary.account.assetCode, locale);
  const gain = summary.unrealizedGain.value;
  switch (code) {
    case 'TOTAL_VALUE':
      return summary.totalValue === null
        ? t('portfolio.kpiValue.notValued')
        : money(summary.totalValue);
    case 'UNREALIZED_GAIN':
      return gain === null
        ? notCalculable
        : areAmountsHidden()
          ? AMOUNT_MASK
          : `${gain.startsWith('-') ? t('portfolio.kpiValue.loss') : t('portfolio.kpiValue.gain')}${money(gain.replace(/^-/, ''))}${gainPerformance(summary.unrealizedGain.performance, locale)}`;
    case 'CASH':
      return money(summary.cash);
    case 'NET_DEPOSITS':
      return money(summary.netDeposits.value);
    case 'COSTS':
      return money(summary.costs.total);
    case 'RECONCILIATION':
      return summary.reconciliation.status === 'RECONCILED'
        ? t('portfolio.kpiValue.reconciled')
        : t('portfolio.kpiValue.toCheck', { count: summary.reconciliation.warnings.length });
  }
}

/** The server's ratio in percent beside the amount, signed in words-free form; nothing when the cost is unknown. */
function gainPerformance(performance: string | null, locale: string): string {
  if (performance === null) return '';
  const negative = performance.startsWith('-');
  const text = formatRatioPercentage(performance.replace(/^-/, ''), locale);
  return ` (${negative ? '−' : '+'}${text})`;
}
