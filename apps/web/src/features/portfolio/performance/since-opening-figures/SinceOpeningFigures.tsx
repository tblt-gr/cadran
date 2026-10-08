import { useState } from 'react';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import {
  figureKeys,
  figureLabels,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import { openPerformanceSource } from '@/features/portfolio/performance/openPerformanceSource';
import { PerformanceValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import styles from './SinceOpeningFigures.module.css';

/** The since-opening gain and returns, read from the `INCEPTION` response; each figure opens its explanation. */
export function SinceOpeningFigures({
  accountId,
  asOf,
  frozen = false,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
}) {
  const [explaining, setExplaining] = useState<FigureKey | null>(null);
  const inception = usePortfolioPerformance(accountId, 'INCEPTION', undefined, !frozen);
  const performance = inception.data ?? null;
  const context = {
    accountId: performance?.accountId ?? '',
    assetCode: performance?.assetCode ?? '',
    residual: performance?.gain.decomposition.residual ?? null,
  };
  return (
    <section aria-labelledby="since-opening-title" className={styles.section}>
      <h3 id="since-opening-title">Depuis l'ouverture</h3>
      {inception.isError ? (
        <OperationsState error title="La performance est indisponible">
          <button
            className="secondary-action"
            type="button"
            onClick={() => void inception.refetch()}
          >
            Réessayer
          </button>
        </OperationsState>
      ) : (
        <div className={styles.figures}>
          {figureKeys.map((key) => (
            <PerformanceValue
              context={context}
              figure={performance ? performance[key] : null}
              key={key}
              label={figureLabels[key]}
              onExplain={performance ? () => setExplaining(key) : null}
            />
          ))}
        </div>
      )}
      {explaining && performance ? (
        <PerformanceExplanationModal
          figure={explaining}
          onClose={() => setExplaining(null)}
          onSource={(source) => {
            setExplaining(null);
            openPerformanceSource(accountId, asOf, source);
          }}
          performance={performance}
        />
      ) : null}
    </section>
  );
}
