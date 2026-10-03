import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  kpiLabels,
  reasonLabels,
  warningLabels,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import styles from './PortfolioKpiGrid.module.css';
export function PortfolioKpiGrid({
  summary,
  asOf,
  onExplain,
}: {
  summary: PortfolioOverviewSummary | null;
  asOf: string;
  onExplain: (code: KpiCode) => void;
}) {
  const money = (value: string | null) =>
    value === null ? 'Non calculable' : formatAmount(value, summary!.account.assetCode, 'fr');
  const gain = summary?.unrealizedGain.value;
  const valueTones: Partial<Record<KpiCode, string>> = {
    UNREALIZED_GAIN: gain == null ? '' : gain.startsWith('-') ? styles.negative : styles.positive,
    CASH: summary?.cash.startsWith('-') ? styles.negative : '',
  };
  const values: Record<KpiCode, string> | null = summary
    ? {
        TOTAL_VALUE: summary.totalValue === null ? 'Non valorisé' : money(summary.totalValue),
        UNREALIZED_GAIN:
          gain == null
            ? 'Non calculable'
            : `${gain.startsWith('-') ? 'Perte −' : 'Gain +'}${money(gain.replace(/^-/, ''))}`,
        CASH: money(summary.cash),
        NET_DEPOSITS: money(summary.netDeposits.value),
        COSTS: money(summary.costs.total),
        RECONCILIATION:
          summary.reconciliation.status === 'RECONCILED'
            ? 'Rapproché'
            : `À vérifier (${summary.reconciliation.warnings.length})`,
      }
    : null;
  return (
    <section
      aria-label="Indicateurs du portefeuille"
      aria-busy={!summary}
      className={styles.grid}
      data-testid={!summary ? 'portfolio-kpi-skeleton' : undefined}
    >
      {(Object.keys(kpiLabels) as KpiCode[]).map((code) => (
        <article className={`card ${styles.kpi}`} key={code}>
          <div className={styles.heading}>
            <h3>
              {kpiLabels[code]}
              {code === 'NET_DEPOSITS' || code === 'COSTS' ? ` (${asOf.slice(0, 4)})` : ''}
            </h3>
            <InfoButton
              disabled={!summary}
              label={`Expliquer : ${kpiLabels[code]}`}
              onClick={() => onExplain(code)}
            />
          </div>
          <div className={`${styles.value} ${valueTones[code] ?? ''}`}>
            {values ? (
              code === 'RECONCILIATION' ? (
                <StatusBadge
                  tone={summary!.reconciliation.status === 'RECONCILED' ? 'positive' : 'warning'}
                >
                  {values[code]}
                </StatusBadge>
              ) : (
                values[code]
              )
            ) : (
              <SkeletonBlock className={styles.valueSkeleton} />
            )}
          </div>
          <p className={styles.note}>
            {code === 'UNREALIZED_GAIN'
              ? summary?.valuation
                ? `au ${formatCalendarNumericDay(summary.valuation.valuedOn)}, au prix de revient moyen`
                : 'au prix de revient moyen'
              : code === 'NET_DEPOSITS' || code === 'COSTS'
                ? `Du ${asOf.slice(0, 4)}-01-01 au ${asOf}`
                : code === 'TOTAL_VALUE' && summary?.valuation
                  ? `au ${summary.valuation.valuedOn}`
                  : `au ${asOf}`}
          </p>
          {summary?.explanations[code].reason ? (
            <StatusBadge tone="warning">
              {reasonLabels[summary.explanations[code].reason!] ??
                summary.explanations[code].reason!}
            </StatusBadge>
          ) : null}
          {code === 'RECONCILIATION' && summary?.reconciliation.warnings.length ? (
            <ul>
              {summary.reconciliation.warnings.map((warning) => (
                <li key={warning.code}>
                  {(warningLabels[warning.code] ?? warning.code).replace(/\.$/, '')}. Signalements :{' '}
                  {warning.count}.
                </li>
              ))}
            </ul>
          ) : null}
        </article>
      ))}
    </section>
  );
}
