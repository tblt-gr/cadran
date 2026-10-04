import { useEffect, useState } from 'react';
import { useMediaQuery } from '@/hooks/use-media-query';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import type { ExplainTarget } from './explainTarget';
import { MonthExplanation } from './MonthExplanation';
import { PerformanceIndexChart } from './performance-index-chart/PerformanceIndexChart';
import { PerformanceValueChart } from './performance-value-chart/PerformanceValueChart';
import { PerformanceMonthCards } from './performance-month-cards/PerformanceMonthCards';
import { PerformanceMonthTable } from './performance-month-table/PerformanceMonthTable';
import { PerformanceSummary } from './performance-summary/PerformanceSummary';
import { performanceYear } from './performanceYear';
import { PerformanceTabSkeleton } from './PerformanceTabSkeleton';
import { useAnnualPerformance } from './useAnnualPerformance';
import { YearNavigator } from './year-navigator/YearNavigator';
import styles from './PerformanceTab.module.css';

/** The Performance tab: year navigator, year and since-opening figures, chained curve and month table. */
export function PerformanceTab({ accountId, asOf }: { accountId: string; asOf: string }) {
  const [year, setYear] = useState(() => performanceYear(asOf));
  const [explaining, setExplaining] = useState<ExplainTarget | null>(null);
  const narrow = useMediaQuery('(max-width: 767px)');
  const annual = useAnnualPerformance(accountId, year);
  const yearPerformance = usePortfolioPerformance(accountId, 'YEAR', String(year));

  useEffect(() => {
    const update = () => setYear(performanceYear(asOf));
    window.addEventListener('popstate', update);
    return () => window.removeEventListener('popstate', update);
  }, [asOf]);

  function changeYear(next: number) {
    const url = new URL(window.location.href);
    url.searchParams.set('year', String(next));
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    setYear(next);
  }

  if (annual.isError) {
    const outOfRange =
      annual.error instanceof OperationRequestError &&
      annual.error.code === 'invalid_performance_period';
    return (
      <div className={styles.tab}>
        <OperationsState error title="La performance est indisponible">
          {outOfRange ? <p>Cette année n'est pas disponible pour ce compte.</p> : null}
          {outOfRange ? (
            <button
              className="secondary-action"
              onClick={() => {
                const current = Number(asOf.slice(0, 4));
                if (current === year) void annual.refetch();
                else changeYear(current);
              }}
              type="button"
            >
              Revenir à l'année en cours
            </button>
          ) : (
            <button
              className="secondary-action"
              onClick={() => void annual.refetch()}
              type="button"
            >
              Réessayer
            </button>
          )}
        </OperationsState>
      </div>
    );
  }
  if (!annual.data) return <PerformanceTabSkeleton year={year} />;

  const data = annual.data;
  const withoutValuation = data.yearTotal.startValue === null && data.yearTotal.endValue === null;
  const explained = explaining?.kind === 'year' ? yearPerformance.data : undefined;

  return (
    <div className={styles.tab}>
      <YearNavigator bounds={data.availableYears} onChange={changeYear} year={year} />
      <PerformanceSummary
        onExplain={setExplaining}
        year={year}
        yearPerformance={yearPerformance.data ?? null}
      />
      <PerformanceValueChart annual={data} year={year} />
      {withoutValuation ? (
        <p className={`card ${styles.notice}`}>Aucune valorisation pour cette année</p>
      ) : null}
      {narrow ? (
        <PerformanceMonthCards annual={data} onExplain={setExplaining} />
      ) : (
        <section className={`card ${styles.panel}`}>
          <PerformanceMonthTable annual={data} onExplain={setExplaining} />
        </section>
      )}
      <PerformanceIndexChart annual={data} />
      {explaining?.kind === 'month' ? (
        <MonthExplanation
          accountId={accountId}
          figure={explaining.figure}
          month={explaining.month}
          onClose={() => setExplaining(null)}
        />
      ) : explaining && explained ? (
        <PerformanceExplanationModal
          figure={explaining.figure}
          onClose={() => setExplaining(null)}
          onSource={() => setExplaining(null)}
          performance={explained}
        />
      ) : null}
    </div>
  );
}
