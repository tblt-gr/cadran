import type { PortfolioOverviewSummary } from '@cadran/api-client';
import type { KpiCode } from '@/features/portfolio/portfolio-account/overviewLabels';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

/** One indicator of the summary in words, as the grid shows it and its explanation repeats it. */
export function kpiValueText(summary: PortfolioOverviewSummary, code: KpiCode): string {
  const money = (value: string | null) =>
    value === null ? 'Non calculable' : formatAmount(value, summary.account.assetCode, 'fr');
  const gain = summary.unrealizedGain.value;
  switch (code) {
    case 'TOTAL_VALUE':
      return summary.totalValue === null ? 'Non valorisé' : money(summary.totalValue);
    case 'UNREALIZED_GAIN':
      return gain === null
        ? 'Non calculable'
        : `${gain.startsWith('-') ? 'Perte −' : 'Gain +'}${money(gain.replace(/^-/, ''))}${gainPerformance(summary.unrealizedGain.performance)}`;
    case 'CASH':
      return money(summary.cash);
    case 'NET_DEPOSITS':
      return money(summary.netDeposits.value);
    case 'COSTS':
      return money(summary.costs.total);
    case 'RECONCILIATION':
      return summary.reconciliation.status === 'RECONCILED'
        ? 'Rapproché'
        : `À vérifier (${summary.reconciliation.warnings.length})`;
  }
}

/** The server's ratio in percent beside the amount, signed in words-free form; nothing when the cost is unknown. */
function gainPerformance(performance: string | null): string {
  if (performance === null) return '';
  const negative = performance.startsWith('-');
  const text = formatRatioPercentage(performance.replace(/^-/, ''), 'fr');
  return ` (${negative ? '−' : '+'}${text})`;
}
