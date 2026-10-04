import type { ReactNode } from 'react';
import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  kpiLabels,
  reasonLabels,
  warningLabels,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import { KpiFigure } from './KpiFigure';
import styles from './PortfolioKpiGrid.module.css';

const secondary: KpiCode[] = ['CASH', 'NET_DEPOSITS', 'COSTS', 'RECONCILIATION'];

/**
 * The portfolio summary read in one place: the total value leads, the unrealized gain sits
 * beside it, the other indicators follow on one strip. The Au date is the page's own control,
 * so a note only appears when an indicator is dated otherwise (valuation day, year to date).
 */
export function PortfolioKpiGrid({
  summary,
  asOf,
  onExplain,
  qualifiers,
  footer,
}: {
  summary: PortfolioOverviewSummary | null;
  asOf: string;
  onExplain: (code: KpiCode) => void;
  /** Valuation notices shown under the total value they qualify. */
  qualifiers?: ReactNode;
  /** A section closing the panel, such as the since-opening performance. */
  footer?: ReactNode;
}) {
  const money = (value: string | null) =>
    value === null ? 'Non calculable' : formatAmount(value, summary!.account.assetCode, 'fr');
  const year = asOf.slice(0, 4);
  const yearToDate = `Du ${formatCalendarNumericDay(`${year}-01-01`)} au ${formatCalendarNumericDay(asOf)}`;
  const valuedOn = summary?.valuation ? formatCalendarNumericDay(summary.valuation.valuedOn) : null;
  const gain = summary?.unrealizedGain.value;
  const tones: Partial<Record<KpiCode, string>> = {
    UNREALIZED_GAIN: gain == null ? '' : gain.startsWith('-') ? styles.negative : styles.positive,
    CASH: summary?.cash.startsWith('-') ? styles.negative : '',
  };
  const values: Record<KpiCode, ReactNode> | null = summary
    ? {
        TOTAL_VALUE: summary.totalValue === null ? 'Non valorisé' : money(summary.totalValue),
        UNREALIZED_GAIN:
          gain == null
            ? 'Non calculable'
            : `${gain.startsWith('-') ? 'Perte −' : 'Gain +'}${money(gain.replace(/^-/, ''))}`,
        CASH: money(summary.cash),
        NET_DEPOSITS: money(summary.netDeposits.value),
        COSTS: money(summary.costs.total),
        RECONCILIATION: (
          <StatusBadge
            tone={summary.reconciliation.status === 'RECONCILED' ? 'positive' : 'warning'}
          >
            {summary.reconciliation.status === 'RECONCILED'
              ? 'Rapproché'
              : `À vérifier (${summary.reconciliation.warnings.length})`}
          </StatusBadge>
        ),
      }
    : null;
  const notes: Partial<Record<KpiCode, string>> = {
    TOTAL_VALUE: valuedOn ? `Valorisation du ${valuedOn}` : undefined,
    UNREALIZED_GAIN: valuedOn
      ? `au ${valuedOn}, au prix de revient moyen`
      : 'au prix de revient moyen',
    NET_DEPOSITS: yearToDate,
    COSTS: yearToDate,
  };
  const figure = (code: KpiCode, size: 'display' | 'lead' | 'compact') => {
    const label =
      code === 'NET_DEPOSITS' || code === 'COSTS' ? `${kpiLabels[code]} ${year}` : kpiLabels[code];
    const reason = summary?.explanations[code].reason;
    return (
      <KpiFigure
        explainLabel={`Expliquer : ${kpiLabels[code]}`}
        key={code}
        label={label}
        note={notes[code]}
        onExplain={summary ? () => onExplain(code) : null}
        reason={reason ? (reasonLabels[reason] ?? reason) : undefined}
        size={size}
        tone={tones[code]}
        value={values ? values[code] : null}
      />
    );
  };
  const warnings = summary?.reconciliation.warnings ?? [];

  return (
    <section
      aria-label="Indicateurs du portefeuille"
      aria-busy={!summary}
      className={`card ${styles.summary}`}
      data-testid={!summary ? 'portfolio-kpi-skeleton' : undefined}
    >
      <div className={styles.lead}>
        <div className={styles.total}>
          {figure('TOTAL_VALUE', 'display')}
          {qualifiers ? <div className={styles.qualifiers}>{qualifiers}</div> : null}
        </div>
        {figure('UNREALIZED_GAIN', 'lead')}
      </div>
      <div className={styles.strip}>{secondary.map((code) => figure(code, 'compact'))}</div>
      {warnings.length ? (
        <div className={styles.review}>
          <p>Points à vérifier</p>
          <ul>
            {warnings.map((warning) => (
              <li key={warning.code}>
                {(warningLabels[warning.code] ?? warning.code).replace(/\.$/, '')}. Signalements :{' '}
                {warning.count}.
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      {footer}
    </section>
  );
}
