import type { PortfolioPerformance } from '@cadran/api-client';
import { useId } from 'react';
import { PerformanceValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { figureKeys, figureLabels } from '@/features/portfolio/performance/performanceFigures';
import type { ExplainTarget } from '@/features/portfolio/performance/performance-tab/explainTarget';
import styles from './PerformanceSummary.module.css';

function Row({
  title,
  performance,
  target,
  onExplain,
}: {
  title: string;
  /** `null` while loading: only the values become blocks. */
  performance: PortfolioPerformance | null;
  target: (figure: (typeof figureKeys)[number]) => ExplainTarget;
  onExplain: (target: ExplainTarget) => void;
}) {
  const titleId = useId();
  const context = {
    accountId: performance?.accountId ?? '',
    assetCode: performance?.assetCode ?? '',
    residual: performance?.gain.decomposition.residual ?? null,
  };
  return (
    <section aria-labelledby={titleId} className={`card ${styles.row}`}>
      <h3 id={titleId}>{title}</h3>
      <div className={styles.figures}>
        {figureKeys.map((key) => (
          <PerformanceValue
            context={context}
            figure={performance ? performance[key] : null}
            key={key}
            label={figureLabels[key]}
            onExplain={performance ? () => onExplain(target(key)) : null}
          />
        ))}
      </div>
    </section>
  );
}

/** The year and since-opening figures, from the `YEAR` and `INCEPTION` responses of the performance endpoint. */
export function PerformanceSummary({
  year,
  yearPerformance,
  inceptionPerformance,
  onExplain,
}: {
  year: number;
  yearPerformance: PortfolioPerformance | null;
  inceptionPerformance: PortfolioPerformance | null;
  onExplain: (target: ExplainTarget) => void;
}) {
  return (
    <div className={styles.summary}>
      <Row
        onExplain={onExplain}
        performance={yearPerformance}
        target={(figure) => ({ kind: 'year', figure })}
        title={`Année ${year}`}
      />
      <Row
        onExplain={onExplain}
        performance={inceptionPerformance}
        target={(figure) => ({ kind: 'inception', figure })}
        title="Depuis l'ouverture"
      />
    </div>
  );
}
