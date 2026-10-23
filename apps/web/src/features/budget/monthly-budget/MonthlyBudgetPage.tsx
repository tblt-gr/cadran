import { readMonthlyLedger } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { workspaceToday } from '@/lib/workspaceTime';
import { monthHref, parseBudgetMonth, readAxis, type BudgetAxis } from './budgetPeriod';
import { useActiveBudgetAccounts } from './budget-creation-modals/useActiveBudgetAccounts';
import {
  BudgetCreationModals,
  type BudgetCreationDraft,
} from './budget-creation-modals/BudgetCreationModals';
import { LedgerPanels } from './ledger-panels/LedgerPanels';
import { useBoundedMonth } from '@/features/reports/useBoundedMonth';
import { formatMonthLabel } from '@/features/reports/MonthlyMonthNavigator';
import { MonthNavigator } from './month-navigator/MonthNavigator';
import { MonthlyBudgetPageSkeleton } from './MonthlyBudgetPageSkeleton';
import { MovementEditModal } from './movement-edit-modal/MovementEditModal';
import { MonthlyRecap } from './monthly-recap/MonthlyRecap';
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
  const ledger = useQuery({
    enabled: parsed.kind === 'valid' && history.status === 'ready' && !history.pendingCorrection,
    queryKey: ['monthly-ledger', queryMonth, axis],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyLedger({
        ...authApiOptions(),
        query: { month: queryMonth, ...(axis ? { axis } : {}) },
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
    `/budget/${periodKey}`,
    monthLabel === null
      ? null
      : t('budget.monthly.title', {
          month: monthLabel.charAt(0).toLocaleLowerCase(i18n.language) + monthLabel.slice(1),
        }),
  );

  if (parsed.kind !== 'valid') {
    return (
      <section className={`card ${styles.routeError}`} role="alert">
        <h2>
          {t(
            parsed.kind === 'future'
              ? 'budget.monthly.route.futureTitle'
              : 'budget.monthly.route.invalidTitle',
          )}
        </h2>
        <p>
          {t(
            parsed.kind === 'future'
              ? 'budget.monthly.route.futureDescription'
              : 'budget.monthly.route.invalidDescription',
          )}
        </p>
        <a
          className="secondary-action"
          href={monthHref(today.slice(0, 7), axis)}
          onClick={(event) => handleClientNavigation(event, monthHref(today.slice(0, 7), axis))}
        >
          {t('budget.monthly.route.recover')}
        </a>
      </section>
    );
  }

  const month = parsed.month;

  const currentMonth = today.slice(0, 7);
  function changeAxis(nextAxis: BudgetAxis | null) {
    const href = monthHref(month!, nextAxis);
    window.history.pushState({}, '', href);
    setAxis(nextAxis);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  async function refreshAfterSave() {
    setSaved(true);
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['monthly-bounds'] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger-movements', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-recap', month] }),
    ]);
  }

  return (
    <div className={styles.page}>
      {saved ? <Toast onDismiss={() => setSaved(false)}>{t('budget.monthly.saved')}</Toast> : null}

      <HeaderActions>
        <a
          aria-label={t('budget.monthly.managePlans')}
          className="icon-button"
          href="/budget/plans"
          onClick={(event) => handleClientNavigation(event, '/budget/plans')}
          title={t('budget.monthly.managePlans')}
        >
          <Icon name="budget" size={18} />
        </a>
      </HeaderActions>

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

      {history.status === 'error' ? null : ledger.isPending && ledger.data === undefined ? (
        <MonthlyBudgetPageSkeleton axis={axis} status={t('budget.monthly.loading')} />
      ) : ledger.isError ? (
        <section className={`card ${styles.routeError}`} role="alert">
          <h2>{t('budget.monthly.error.title')}</h2>
          <p>{monthLabel}</p>
          <p>{t('budget.monthly.error.description')}</p>
          <button className="secondary-action" onClick={() => void ledger.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        </section>
      ) : (
        <div className={styles.workbook}>
          <LedgerPanels
            activeAccountIds={activeAccountIds}
            axis={axis}
            data={ledger.data}
            language={i18n.language}
            month={month}
            expenseRowsPending={ledger.isPlaceholderData}
            onAxisChange={changeAxis}
            onDraft={setDraft}
            onEditTransaction={setEditingTransactionId}
          />
          <MonthlyRecap month={month} />
        </div>
      )}

      <BudgetCreationModals
        close={() => setDraft(null)}
        draft={draft}
        month={month}
        onSaved={refreshAfterSave}
        today={today}
      />

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
