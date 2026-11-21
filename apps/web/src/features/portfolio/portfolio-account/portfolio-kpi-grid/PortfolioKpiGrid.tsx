import { signedPresentation } from '@/lib/decimal';
import { areAmountsHidden } from '@/lib/amountPrivacy';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import {
  kpiLabel,
  reasonLabel,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import { KpiFigure } from './KpiFigure';
import { kpiValueText } from './kpiValueText';
import { ReconciliationReview } from './reconciliation-review/ReconciliationReview';
import styles from './PortfolioKpiGrid.module.css';

const secondary: KpiCode[] = ['CASH'];

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
}: {
  summary: PortfolioOverviewSummary | null;
  embedded?: boolean;
  asOf: string;
  onExplain: (code: KpiCode) => void;
  /** Valuation notices shown under the total value they qualify. */
  qualifiers?: ReactNode;
}) {
  const { t, i18n } = useTranslation();
  const [reviewing, setReviewing] = useState(false);
  const needsReview = summary?.reconciliation.status === 'NEEDS_REVIEW';
  const year = asOf.slice(0, 4);
  const gain = summary?.unrealizedGain.value;
  const tones: Partial<Record<KpiCode, string>> = {
    UNREALIZED_GAIN:
      gain == null
        ? ''
        : signedPresentation('', gain).sign < 0
          ? styles.negative
          : signedPresentation('', gain).sign > 0
            ? styles.positive
            : '',
    CASH: summary?.cash.startsWith('-') ? styles.negative : '',
  };
  const values: Record<KpiCode, ReactNode> | null = summary
    ? {
        TOTAL_VALUE: kpiValueText(summary, 'TOTAL_VALUE', t, i18n.language),
        UNREALIZED_GAIN: kpiValueText(summary, 'UNREALIZED_GAIN', t, i18n.language),
        CASH: kpiValueText(summary, 'CASH', t, i18n.language),
        NET_DEPOSITS: kpiValueText(summary, 'NET_DEPOSITS', t, i18n.language),
        COSTS: kpiValueText(summary, 'COSTS', t, i18n.language),
        RECONCILIATION: null,
      }
    : null;
  const figure = (code: KpiCode, size: 'display' | 'lead' | 'compact') => {
    const label =
      code === 'NET_DEPOSITS' || code === 'COSTS'
        ? `${kpiLabel(t, code)} ${year}`
        : kpiLabel(t, code);
    const reason = summary?.explanations[code].reason;
    return (
      <KpiFigure
        explainLabel={t('portfolio.account.kpiGrid.explain', { label: kpiLabel(t, code) })}
        key={code}
        label={label}
        onExplain={summary ? () => onExplain(code) : null}
        reason={reason ? (reasonLabel(t, reason) ?? reason) : undefined}
        size={size}
        tone={areAmountsHidden() ? '' : tones[code]}
        value={values ? values[code] : null}
      />
    );
  };
  return (
    <section
      aria-label={t('portfolio.account.kpiGrid.label')}
      aria-busy={!summary}
      className={`${embedded ? '' : 'card'} ${styles.summary}`}
      data-testid={!summary ? 'portfolio-kpi-skeleton' : undefined}
    >
      <div className={styles.strip}>
        {figure('TOTAL_VALUE', 'display')}
        {figure('UNREALIZED_GAIN', 'lead')}
        {secondary.map((code) => figure(code, 'compact'))}
      </div>
      {qualifiers || (needsReview && summary) ? (
        <div className={styles.qualifiers}>
          {qualifiers}
          {needsReview && summary ? (
            <button className={styles.pill} onClick={() => setReviewing(true)} type="button">
              <StatusBadge tone="warning">
                {kpiValueText(summary, 'RECONCILIATION', t, i18n.language)}
              </StatusBadge>
            </button>
          ) : null}
        </div>
      ) : null}
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
