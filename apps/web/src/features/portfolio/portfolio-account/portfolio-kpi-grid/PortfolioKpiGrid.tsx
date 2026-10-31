import { useState, type ReactNode } from 'react';
import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  kpiLabels,
  reasonLabels,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import { KpiFigure } from './KpiFigure';
import { kpiValueText } from './kpiValueText';
import { ReconciliationReview } from './reconciliation-review/ReconciliationReview';
import styles from './PortfolioKpiGrid.module.css';

const secondary: KpiCode[] = ['CASH', 'NET_DEPOSITS', 'COSTS', 'RECONCILIATION'];

/**
 * The portfolio summary read in one place: the total value leads, the unrealized gain sits
 * beside it, the other indicators follow on one strip; the Rapprochement pill opens the points to
 * review.
 * The Au date is the page's own control, so a note only appears when an indicator is dated
 * otherwise (valuation day, year to date).
 */
export function PortfolioKpiGrid({
  summary,
  asOf,
  onExplain,
  qualifiers,
  children,
}: {
  summary: PortfolioOverviewSummary | null;
  asOf: string;
  onExplain: (code: KpiCode) => void;
  /** Valuation notices shown under the total value they qualify. */
  qualifiers?: ReactNode;
  /** More figures continuing the strip: the since-opening returns. */
  children?: ReactNode;
}) {
  const [reviewing, setReviewing] = useState(false);
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
        TOTAL_VALUE: kpiValueText(summary, 'TOTAL_VALUE'),
        UNREALIZED_GAIN: kpiValueText(summary, 'UNREALIZED_GAIN'),
        CASH: kpiValueText(summary, 'CASH'),
        NET_DEPOSITS: kpiValueText(summary, 'NET_DEPOSITS'),
        COSTS: kpiValueText(summary, 'COSTS'),
        RECONCILIATION:
          summary.reconciliation.status === 'RECONCILED' ? (
            <StatusBadge tone="positive">{kpiValueText(summary, 'RECONCILIATION')}</StatusBadge>
          ) : (
            <button className={styles.pill} onClick={() => setReviewing(true)} type="button">
              <StatusBadge tone="warning">{kpiValueText(summary, 'RECONCILIATION')}</StatusBadge>
            </button>
          ),
      }
    : null;
  const cash = summary?.explanations.CASH;
  const cashOn = cash && valuedOn ? formatCalendarNumericDay(cash.period.to) : null;
  const notes: Partial<Record<KpiCode, string>> = {
    TOTAL_VALUE: valuedOn ? `Valorisation du ${valuedOn}` : undefined,
    CASH: cashOn
      ? cash?.quality === 'OBSERVED'
        ? `Relevé du ${cashOn}`
        : `Calculées au ${cashOn}`
      : undefined,
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
      {children}
      {reviewing && summary ? (
        <ReconciliationReview
          accountId={summary.account.id}
          asOf={asOf}
          onClose={() => setReviewing(false)}
          warnings={summary.reconciliation.warnings}
        />
      ) : null}
    </section>
  );
}
