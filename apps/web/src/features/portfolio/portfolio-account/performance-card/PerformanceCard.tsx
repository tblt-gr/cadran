import type { PortfolioOverviewSource } from '@cadran/api-client';
import { useState, type RefObject } from 'react';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import { PerformanceValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import {
  figureKeys,
  figureLabels,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './PerformanceCard.module.css';

/** Gain and the three returns of the year of the Au date, each with its quality and explanation. */
export function PerformanceCard({
  accountId,
  asOf,
  frozen = false,
  onSource,
  sourceReturnFocus,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  onSource: (source: PortfolioOverviewSource) => void;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const [explaining, setExplaining] = useState<FigureKey | null>(null);
  const performance = usePortfolioPerformance(accountId, 'YEAR', asOf.slice(0, 4), !frozen);
  const data = performance.data;
  const gain = data?.gain;
  const context = {
    accountId,
    assetCode: data?.assetCode ?? '',
    residual: gain?.decomposition.residual ?? null,
  };
  const subtitle = !gain
    ? null
    : gain.reasons.some((reason) => reason.code === 'PERIOD_IN_PROGRESS')
      ? 'Depuis le 1er janvier'
      : gain.from && gain.to
        ? `Du ${formatCalendarNumericDay(gain.from)} au ${formatCalendarNumericDay(gain.to)}`
        : null;
  const withoutValuation = data !== undefined && data.start === null && data.end === null;

  return (
    <section aria-labelledby="portfolio-performance-title" className={`card ${styles.card}`}>
      <div className={styles.header}>
        <h2 id="portfolio-performance-title">Performance de l'année</h2>
        {subtitle ? (
          <p className={styles.subtitle}>{subtitle}</p>
        ) : performance.isError ? null : (
          <SkeletonBlock className={styles.subtitleSkeleton} />
        )}
      </div>
      {performance.isError ? (
        <div className={styles.error} role="alert">
          <p>La performance est indisponible.</p>
          <button
            className="secondary-action"
            onClick={() => void performance.refetch()}
            type="button"
          >
            Réessayer
          </button>
        </div>
      ) : (
        <>
          {data || frozen ? null : (
            <p className="sr-only" role="status">
              Chargement de la performance…
            </p>
          )}
          <div className={styles.figures}>
            {figureKeys.map((key) => (
              <PerformanceValue
                context={context}
                emptyLabel={withoutValuation ? 'Aucune valorisation' : undefined}
                figure={data ? data[key] : null}
                key={key}
                label={figureLabels[key]}
                onExplain={
                  data
                    ? () => {
                        if (sourceReturnFocus)
                          sourceReturnFocus.current =
                            document.activeElement instanceof HTMLElement
                              ? document.activeElement
                              : null;
                        setExplaining(key);
                      }
                    : null
                }
              />
            ))}
          </div>
        </>
      )}
      {explaining && data ? (
        <PerformanceExplanationModal
          figure={explaining}
          onClose={() => setExplaining(null)}
          onSource={(source) => {
            setExplaining(null);
            onSource(source);
          }}
          performance={data}
        />
      ) : null}
    </section>
  );
}
