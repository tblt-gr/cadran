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

const secondary: KpiCode[] = ['CASH', 'NET_DEPOSITS', 'COSTS'];

/**
 * A full calendar year is already named on the year figures. A year in progress, or a past year
 * that does not run from 1 January to 31 December, keeps that window once, under the total.
 */
function periodCaption(from: string, to: string): string | undefined {
  const sameYear = from.slice(0, 4) === to.slice(0, 4);
  if (sameYear && from.endsWith('-01-01') && to.endsWith('-12-31')) return undefined;
  return `Du ${formatCalendarNumericDay(from)} au ${formatCalendarNumericDay(to)}`;
}

/**
 * The portfolio summary read in one place: the total value leads, the unrealized gain sits
 * beside it, the other indicators follow on one strip. A reconciled account stays silent. Points
 * to review sit at the top right and open the list of what is wrong.
 */
export function PortfolioKpiGrid({
  summary,
  embedded = false,
  asOf,
  onExplain,
  qualifiers,
  children,
}: {
  summary: PortfolioOverviewSummary | null;
  embedded?: boolean;
  asOf: string;
  onExplain: (code: KpiCode) => void;
  /** Valuation notices shown under the total value they qualify. */
  qualifiers?: ReactNode;
  /** More figures continuing the strip: the since-opening returns. */
  children?: ReactNode;
}) {
  const [reviewing, setReviewing] = useState(false);
  const needsReview = summary?.reconciliation.status === 'NEEDS_REVIEW';
  const year = asOf.slice(0, 4);
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
        RECONCILIATION: null,
      }
    : null;
  const period = summary
    ? periodCaption(summary.netDeposits.from, summary.netDeposits.to)
    : undefined;
  const figure = (code: KpiCode, size: 'display' | 'lead' | 'compact') => {
    const label =
      code === 'NET_DEPOSITS' || code === 'COSTS' ? `${kpiLabels[code]} ${year}` : kpiLabels[code];
    const reason = summary?.explanations[code].reason;
    return (
      <KpiFigure
        explainLabel={`Expliquer : ${kpiLabels[code]}`}
        key={code}
        label={label}
        note={code === 'TOTAL_VALUE' ? period : undefined}
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
      className={`${embedded ? '' : 'card'} ${styles.summary}`}
      data-testid={!summary ? 'portfolio-kpi-skeleton' : undefined}
    >
      <div className={styles.lead}>
        <div className={styles.total}>
          {figure('TOTAL_VALUE', 'display')}
          {qualifiers ? <div className={styles.qualifiers}>{qualifiers}</div> : null}
        </div>
        <div className={needsReview ? `${styles.side} ${styles.sideTop}` : styles.side}>
          {needsReview && summary ? (
            <button className={styles.pill} onClick={() => setReviewing(true)} type="button">
              <StatusBadge tone="warning">{kpiValueText(summary, 'RECONCILIATION')}</StatusBadge>
            </button>
          ) : null}
          {figure('UNREALIZED_GAIN', 'lead')}
        </div>
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
