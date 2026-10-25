import { useEffect, useState } from 'react';
import { AnnualReportPage } from '@/features/reports/annual/AnnualReportPage';
import { MultiYearReportPage } from '@/features/reports/multi-year/MultiYearReportPage';
import { useMultiYearReport } from '@/features/reports/multi-year/useMultiYearReport';
import { useMonthlyBounds } from '@/features/budget/monthly-budget/useMonthlyBounds';
import { annualHref } from '@/features/budget/monthly-budget/budgetPeriod';
import { MonthNavigator } from '@/features/budget/monthly-budget/month-navigator/MonthNavigator';
import styles from './BudgetYearPage.module.css';
import { ReportToolbar } from './ReportToolbar';
import { readYearView, writeYearView, type YearViewState } from './yearViewSearch';

interface BudgetYearPageProps {
  /** Workspace-local date, `YYYY-MM-DD`. */
  today: string;
  year: number;
}

/** `/budget/YYYY`: the annual report, or its multi-year mode selected through the query string. */
export function BudgetYearPage({ today, year }: BudgetYearPageProps) {
  const [state, setState] = useState<YearViewState>(() => readYearView(window.location.search));
  const bounds = useMonthlyBounds();
  // Unparameterized: the backend's default bounds say whether more than one year exists.
  const years = useMultiYearReport();
  const multiYearAvailable =
    years.data?.availableFrom === undefined ||
    years.data.availableFrom === null ||
    years.data.availableFrom !== years.data.to;
  const currentYear = Number(today.slice(0, 4));

  useEffect(() => {
    const sync = () => setState(readYearView(window.location.search));
    window.addEventListener('popstate', sync);
    return () => window.removeEventListener('popstate', sync);
  }, []);

  function navigate(next: YearViewState) {
    window.history.pushState({}, '', annualHref(year, writeYearView(next)));
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  return (
    <div className={styles.page}>
      <MonthNavigator
        axis={null}
        currentMonth={today.slice(0, 7)}
        firstDataMonth={bounds.data?.firstDataMonth ?? null}
        month={null}
        onRetry={() => void bounds.refetch()}
        status={bounds.data ? 'ready' : bounds.isError ? 'error' : 'loading'}
        year={year}
      />
      <ReportToolbar
        multiYearAvailable={multiYearAvailable}
        onViewChange={(view) => navigate({ ...state, view })}
        view={state.view}
      />
      {state.view === 'multi' ? (
        <MultiYearReportPage
          currentYear={currentYear}
          display={state.display}
          onDisplayChange={(display) => navigate({ ...state, display })}
          onRangeChange={(range) => navigate({ ...state, range })}
          range={state.range}
          year={year}
        />
      ) : (
        <AnnualReportPage year={year} />
      )}
    </div>
  );
}
