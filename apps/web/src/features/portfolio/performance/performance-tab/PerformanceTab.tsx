import { usePerformanceColumns } from './performance-columns/PerformanceColumns';
import type { PortfolioOverviewSource } from '@cadran/api-client';
import { useEffect, useState } from 'react';
import { useMediaQuery } from '@/hooks/use-media-query';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import { openPerformanceSource } from '@/features/portfolio/performance/openPerformanceSource';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import type { ExplainTarget } from './explainTarget';
import { MonthExplanation } from './MonthExplanation';
import { PerformanceMonthCards } from './performance-month-cards/PerformanceMonthCards';
import { PerformanceMonthTable } from './performance-month-table/PerformanceMonthTable';
import { PerformanceSummary } from './performance-summary/PerformanceSummary';
import { performanceYear } from './performanceYear';
import { PerformanceTabSkeleton } from './PerformanceTabSkeleton';
import { useAnnualPerformance } from './useAnnualPerformance';
import { PerformanceToolbar } from './performance-toolbar/PerformanceToolbar';
import styles from './PerformanceTab.module.css';

/**
 * The Performance tab, one year at a time: the year's figures, then each month. The since-opening
 * returns sit in the page summary and the value curve in the page's value history.
 */
export function PerformanceTab({ accountId, asOf }: { accountId: string; asOf: string }) {
  const columns = usePerformanceColumns(accountId);
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

  const offeredYears = annual.data?.availableYears.years;
  useEffect(() => {
    if (!offeredYears || offeredYears.includes(year)) return;
    const next = offeredYears[offeredYears.length - 1];
    if (next === undefined) return;
    const url = new URL(window.location.href);
    url.searchParams.set('year', String(next));
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
    setYear(next);
  }, [offeredYears, year]);

  function changeYear(next: number) {
    const url = new URL(window.location.href);
    url.searchParams.set('year', String(next));
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    setYear(next);
  }

  function openSource(source: PortfolioOverviewSource) {
    setExplaining(null);
    openPerformanceSource(accountId, asOf, source);
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
  if (!annual.data) return <PerformanceTabSkeleton year={year} selected={columns.selected} />;

  const data = annual.data;
  const withoutValuation = data.yearTotal.startValue === null && data.yearTotal.endValue === null;
  const explained = explaining?.kind === 'year' ? yearPerformance.data : undefined;

  return (
    <div className={styles.tab}>
      <PerformanceToolbar
        bounds={data.availableYears}
        onYearChange={changeYear}
        year={year}
        selected={columns.selected}
        onColumnsChange={columns.change}
      />
      <PerformanceSummary
        failed={yearPerformance.isError}
        onExplain={setExplaining}
        onRetry={() => void yearPerformance.refetch()}
        year={year}
        yearPerformance={yearPerformance.data ?? null}
      />
      {withoutValuation ? (
        <p className={`card ${styles.notice}`}>Aucune valorisation pour cette année</p>
      ) : null}
      {narrow ? (
        <PerformanceMonthCards
          selected={columns.selected}
          annual={data}
          onExplain={setExplaining}
        />
      ) : (
        <section className={`card ${styles.panel}`}>
          <PerformanceMonthTable
            selected={columns.selected}
            annual={data}
            onExplain={setExplaining}
          />
        </section>
      )}
      {explaining?.kind === 'month' ? (
        <MonthExplanation
          accountId={accountId}
          figure={explaining.figure}
          month={explaining.month}
          onClose={() => setExplaining(null)}
          onSource={openSource}
        />
      ) : explaining && explained ? (
        <PerformanceExplanationModal
          figure={explaining.figure}
          onClose={() => setExplaining(null)}
          onSource={openSource}
          performance={explained}
        />
      ) : null}
    </div>
  );
}
