import { MonthlyBudgetRouteError } from './monthly-budget-route-error/MonthlyBudgetRouteError';
import { MonthlyBudgetHeader } from './monthly-budget-header/MonthlyBudgetHeader';
import { BudgetNavigation } from '@/features/budget/budget-navigation/BudgetNavigation';
import { MonthlyWorkspace } from './monthly-workspace/MonthlyWorkspace';
import { readMonthlyLedger } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { GoalPanel } from '@/features/goals/goal-panel/GoalPanel';
import { useGoalPanelOpen } from '@/features/goals/goal-panel/goalPanelSearch';
import { useIncludeUpcoming } from '@/features/settings/interface/useIncludeUpcoming';
import { workspaceToday } from '@/lib/workspaceTime';
import { uncategorizedCountKey } from './useUncategorizedCount';
import { monthHref, parseBudgetMonth, readAxis, type BudgetAxis } from './budgetPeriod';
import { useActiveBudgetAccounts } from './budget-creation-modals/useActiveBudgetAccounts';
import {
  BudgetCreationModals,
  type BudgetCreationDraft,
} from './budget-creation-modals/BudgetCreationModals';
import { useBoundedMonth } from './useBoundedMonth';
import { formatMonthLabel } from '@/lib/monthLabel';
import { MonthNavigator } from './month-navigator/MonthNavigator';
import { MovementEditModal } from './movement-edit-modal/MovementEditModal';
import styles from './MonthlyBudgetPage.module.css';

interface MonthlyBudgetPageProps {
  periodKey: string;
  today?: string;
}

export function MonthlyBudgetPage({ periodKey, today: injectedToday }: MonthlyBudgetPageProps) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  // The session (already loaded by AuthGate before this page can render) carries
  // the workspace's own timezone, so the route is validated against the same
  // zone the ledger itself will answer with, never a fixed assumption.
  const workspaceTimeZone = useWorkspaceTimeZone();
  const routeToday = injectedToday ?? workspaceToday(new Date(), workspaceTimeZone);
  const parsed = parseBudgetMonth(periodKey, routeToday);
  const [axis, setAxis] = useState<BudgetAxis | null>(() => readAxis(window.location.search));
  const [draft, setDraft] = useState<BudgetCreationDraft>(null);
  const [editingTransactionId, setEditingTransactionId] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const goalPanel = useGoalPanelOpen();
  const goalsButton = useRef<HTMLButtonElement>(null);
  const queryMonth = parsed.kind === 'valid' ? parsed.month : '';

  useEffect(() => {
    const updateAxis = () => setAxis(readAxis(window.location.search));
    window.addEventListener('popstate', updateAxis);
    return () => window.removeEventListener('popstate', updateAxis);
  }, []);

  // The ledger of a month before the history, or while the bounds are unknown, is never fetched.
  const history = useBoundedMonth({
    month: parsed.kind === 'valid' ? parsed.month : null,
    hrefFor: (first) => monthHref(first, axis),
  });
  const upcoming = useIncludeUpcoming();
  const ledger = useQuery({
    enabled:
      parsed.kind === 'valid' &&
      history.status === 'ready' &&
      !history.pendingCorrection &&
      upcoming.ready,
    queryKey: ['monthly-ledger', queryMonth, axis, upcoming.includeUpcoming],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyLedger({
        ...authApiOptions(),
        query: {
          month: queryMonth,
          includeUpcoming: upcoming.includeUpcoming,
          ...(axis ? { axis } : {}),
        },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('monthly-ledger');
      return result.data;
    },
    // Only an axis change keeps the previous payload; another month never shows foreign figures.
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === queryMonth ? previous : undefined,
    retry: false,
  });

  const activeAccounts = useActiveBudgetAccounts(parsed.kind === 'valid');
  const activeAccountIds = activeAccounts.data
    ? new Set(activeAccounts.data.map((account) => account.id))
    : null;
  const today =
    injectedToday ?? (ledger.data ? workspaceToday(new Date(), ledger.data.timezone) : routeToday);
  // ledger.data.timezone and workspaceTimeZone are the same workspace value;
  // the ledger's own copy is kept as the source of truth once it has answered,
  // since it is what the displayed figures were actually computed against.

  const monthLabel =
    parsed.kind === 'valid'
      ? new Intl.DateTimeFormat(i18n.language, {
          month: 'long',
          timeZone: 'UTC',
          year: 'numeric',
        }).format(new Date(`${parsed.month}-01T12:00:00Z`))
      : null;
  useHeaderTitle(
    monthHref(periodKey, null),
    monthLabel === null
      ? null
      : t('budget.monthly.title', {
          month: monthLabel.charAt(0).toLocaleLowerCase(i18n.language) + monthLabel.slice(1),
        }),
  );

  if (parsed.kind !== 'valid') {
    return <MonthlyBudgetRouteError kind={parsed.kind} href={monthHref(today.slice(0, 7), axis)} />;
  }

  const month = parsed.month;

  const currentMonth = today.slice(0, 7);
  function changeAxis(nextAxis: BudgetAxis | null) {
    const href = monthHref(month!, nextAxis);
    window.history.pushState({}, '', href);
    setAxis(nextAxis);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  async function refreshMonth() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['monthly-bounds'] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger-movements', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-recap', month] }),
      queryClient.invalidateQueries({ queryKey: uncategorizedCountKey(month!) }),
      queryClient.invalidateQueries({ queryKey: ['goal-evaluations'] }),
    ]);
  }

  async function refreshAfterSave() {
    setSaved(true);
    await refreshMonth();
  }

  return (
    <div className={styles.page}>
      {saved ? <Toast onDismiss={() => setSaved(false)}>{t('budget.monthly.saved')}</Toast> : null}

      <MonthlyBudgetHeader
        ready={!ledger.isPending}
        actionsDisabled={
          history.status !== 'error' && ledger.isPending && ledger.data === undefined
        }
        month={month}
        goalsButton={goalsButton}
        onManage={goalPanel.openPanel}
        onClosureChanged={() => void refreshMonth()}
      />

      <BudgetNavigation current="budget" month={month} axis={axis} />

      <MonthNavigator
        axis={axis}
        currentMonth={currentMonth}
        firstDataMonth={history.firstDataMonth}
        month={month}
        onRetry={() => void history.bounds.refetch()}
        status={history.status}
      />
      {history.correctedTo !== null ? (
        <p aria-live="polite" className="sr-only" key={history.correctionKey} role="status">
          {t('budget.monthly.corrected', {
            month: formatMonthLabel(history.correctedTo, i18n.language).toLocaleLowerCase(
              i18n.language,
            ),
          })}
        </p>
      ) : null}

      {history.status === 'error' ? null : ledger.isError ? (
        <section className={`card ${styles.routeError}`} role="alert">
          <h2>{t('budget.monthly.error.title')}</h2>
          <p>{monthLabel}</p>
          <p>{t('budget.monthly.error.description')}</p>
          <button className="secondary-action" onClick={() => void ledger.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        </section>
      ) : (
        <MonthlyWorkspace
          ledger={ledger.data ?? null}
          month={month}
          axis={axis}
          activeAccountIds={activeAccountIds}
          expenseRowsPending={ledger.isPlaceholderData}
          onAxisChange={changeAxis}
          onDraft={setDraft}
          onEditTransaction={setEditingTransactionId}
        />
      )}

      <BudgetCreationModals
        close={() => setDraft(null)}
        draft={draft}
        month={month}
        onSaved={refreshAfterSave}
        today={today}
      />

      {goalPanel.open ? (
        <GoalPanel
          close={goalPanel.closePanel}
          focusGoalId={goalPanel.target?.goalId ?? null}
          returnFocus={goalsButton}
          scope={{ kind: 'month', month }}
        />
      ) : null}

      {editingTransactionId ? (
        <MovementEditModal
          close={() => setEditingTransactionId(null)}
          onSaved={refreshAfterSave}
          transactionId={editingTransactionId}
        />
      ) : null}
    </div>
  );
}
