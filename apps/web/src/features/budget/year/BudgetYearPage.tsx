import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { GoalPanel } from '@/features/goals/goal-panel/GoalPanel';
import { GoalsPanelButton } from '@/features/goals/goal-panel/GoalsPanelButton';
import type { GoalPanelScope } from '@/features/goals/goal-panel/useGoalPanelData';
import { useGoalPanelOpen } from '@/features/goals/goal-panel/goalPanelSearch';
import { AnnualReportPage } from '@/features/reports/annual/AnnualReportPage';
import { MultiYearReportPage } from '@/features/reports/multi-year/MultiYearReportPage';
import {
  annualReportQueryOptions,
  useAnnualReportPreferences,
} from '@/features/reports/annual/useAnnualReport';
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
  const goalPanel = useGoalPanelOpen();
  const goalsButton = useRef<HTMLButtonElement>(null);
  const bounds = useMonthlyBounds();
  const preferences = useAnnualReportPreferences();
  // Unparameterized: the backend's default bounds say whether more than one year exists.
  const years = useMultiYearReport();
  const multiYearAvailable =
    years.data?.availableFrom === undefined ||
    years.data.availableFrom === null ||
    years.data.availableFrom !== years.data.to;
  const currentYear = Number(today.slice(0, 4));
  // The goals counter waits for the report on screen (same queries as the report itself).
  const annualReport = useQuery({
    ...annualReportQueryOptions(year),
    enabled: state.view === 'annual',
  });
  const multiYearReport = useMultiYearReport(
    state.range?.from,
    state.range?.to,
    state.view === 'multi',
  );
  const goalsReady = !(state.view === 'multi' ? multiYearReport : annualReport).isPending;
  // On the multi-year view the goals follow the selected years; until they are known, the year.
  const multiRange =
    state.view === 'multi'
      ? (state.range ?? (years.data ? { from: years.data.from, to: years.data.to } : null))
      : null;
  const goalScope: GoalPanelScope =
    multiRange === null
      ? { kind: 'year', year }
      : {
          kind: 'years',
          years: Array.from(
            { length: Math.max(multiRange.to - multiRange.from + 1, 1) },
            (_, index) => multiRange.from + index,
          ),
        };

  // A figure's `i` opens the panel on its own period: that month, or that year.
  const period = goalPanel.target?.period;
  const panelScope: GoalPanelScope =
    period === undefined
      ? goalScope
      : period.length === 7
        ? { kind: 'month', month: period }
        : { kind: 'year', year: Number(period) };

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
      <HeaderActions>
        <GoalsPanelButton
          buttonRef={goalsButton}
          evaluate={preferences.data?.goalMarks === true}
          onOpen={goalPanel.openPanel}
          ready={goalsReady}
          scope={goalScope}
        />
      </HeaderActions>
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
        multi={
          state.view === 'multi'
            ? {
                display: state.display,
                maxYear: currentYear,
                minYear: years.data?.availableFrom ?? years.data?.from ?? currentYear,
                onDisplayChange: (display) => navigate({ ...state, display }),
                onRangeChange: (range) => navigate({ ...state, range }),
                range:
                  state.range ?? (years.data ? { from: years.data.from, to: years.data.to } : null),
              }
            : undefined
        }
        multiYearAvailable={multiYearAvailable}
        onViewChange={(view) => navigate({ ...state, view })}
        view={state.view}
      />
      {state.view === 'multi' ? (
        <MultiYearReportPage
          key={`${state.display}-${state.range?.from}-${state.range?.to}`}
          display={state.display}
          range={state.range}
          year={year}
        />
      ) : (
        <AnnualReportPage year={year} />
      )}
      {goalPanel.open ? (
        <GoalPanel
          close={goalPanel.closePanel}
          focusGoalId={goalPanel.target?.goalId ?? null}
          returnFocus={goalsButton}
          scope={panelScope}
        />
      ) : null}
    </div>
  );
}
