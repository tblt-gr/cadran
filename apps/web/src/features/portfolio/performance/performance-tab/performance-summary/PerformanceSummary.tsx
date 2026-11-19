import type { PortfolioPerformance } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { PerformanceValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { figureKeys, figureLabelKeys } from '@/features/portfolio/performance/performanceFigures';
import type { ExplainTarget } from '@/features/portfolio/performance/performance-tab/explainTarget';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import styles from './PerformanceSummary.module.css';

function Row({
  title,
  performance,
  failed = false,
  onRetry,
  target,
  onExplain,
}: {
  title: string;
  /** `null` while loading: only the values become blocks. */
  performance: PortfolioPerformance | null;
  failed?: boolean;
  onRetry?: () => void;
  target: (figure: (typeof figureKeys)[number]) => ExplainTarget;
  onExplain: (target: ExplainTarget) => void;
}) {
  const { t } = useTranslation();
  const titleId = useId();
  const context = {
    accountId: performance?.accountId ?? '',
    assetCode: performance?.assetCode ?? '',
    residual: performance?.gain.decomposition.residual ?? null,
  };
  return (
    <section aria-labelledby={titleId} className={`card ${styles.row}`}>
      <h2 id={titleId}>{title}</h2>
      {failed ? (
        <OperationsState error title={t('portfolio.performance.unavailable')}>
          <button className="secondary-action" type="button" onClick={onRetry}>
            {t('actions.retry')}
          </button>
        </OperationsState>
      ) : (
        <div className={styles.figures}>
          {figureKeys.map((key) => (
            <PerformanceValue
              context={context}
              figure={performance ? performance[key] : null}
              key={key}
              withQuality
              label={t(figureLabelKeys[key])}
              onExplain={performance ? () => onExplain(target(key)) : null}
            />
          ))}
        </div>
      )}
    </section>
  );
}

/** The year figures, from the `YEAR` response of the performance endpoint; the since-opening ones sit in the page summary. */
export function PerformanceSummary({
  year,
  yearPerformance,
  failed = false,
  onRetry,
  onExplain,
}: {
  year: number;
  yearPerformance: PortfolioPerformance | null;
  failed?: boolean;
  onRetry?: () => void;
  onExplain: (target: ExplainTarget) => void;
}) {
  const { t } = useTranslation();
  return (
    <Row
      failed={failed}
      onExplain={onExplain}
      onRetry={onRetry}
      performance={yearPerformance}
      target={(figure) => ({ kind: 'year', figure })}
      title={t('portfolio.performance.months.year', { year })}
    />
  );
}
