import { readMonthlyLedger } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import {
  monthHref,
  parseBudgetMonth,
  readAxis,
  workspaceToday,
  type BudgetAxis,
} from './budgetPeriod';
import { useActiveBudgetAccounts } from './budget-creation-modals/useActiveBudgetAccounts';
import {
  BudgetCreationModals,
  type BudgetCreationDraft,
} from './budget-creation-modals/BudgetCreationModals';
import { LedgerPanels } from './ledger-panels/LedgerPanels';
import { MonthNavigator } from './month-navigator/MonthNavigator';
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
  // The route is validated with the fallback zone before any request; once the ledger
  // answers, the workspace's own timezone decides the current day and month.
  const routeToday = injectedToday ?? workspaceToday();
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

  const ledger = useQuery({
    enabled: parsed.kind === 'valid',
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
    retry: false,
  });

  // Kept across month changes: the ledger of the next month is pending, the first year is not.
  const [firstDataMonth, setFirstDataMonth] = useState<string | null>(null);
  const answeredFirstDataMonth = ledger.data?.firstDataMonth;
  if (answeredFirstDataMonth !== undefined && answeredFirstDataMonth !== firstDataMonth) {
    setFirstDataMonth(answeredFirstDataMonth);
  }

  const activeAccounts = useActiveBudgetAccounts(parsed.kind === 'valid');
  const activeAccountIds = activeAccounts.data
    ? new Set(activeAccounts.data.map((account) => account.id))
    : null;
  const today =
    injectedToday ?? (ledger.data ? workspaceToday(new Date(), ledger.data.timezone) : routeToday);

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
  const monthLabel = new Intl.DateTimeFormat(i18n.language, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));

  function changeAxis(nextAxis: BudgetAxis | null) {
    const href = monthHref(month!, nextAxis);
    window.history.pushState({}, '', href);
    setAxis(nextAxis);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  async function refreshAfterSave() {
    setSaved(true);
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-ledger-movements', month] }),
      queryClient.invalidateQueries({ queryKey: ['monthly-recap', month] }),
    ]);
  }

  return (
    <div className={styles.page}>
      {saved ? <Toast onDismiss={() => setSaved(false)}>{t('budget.monthly.saved')}</Toast> : null}

      <section aria-labelledby="monthly-budget-title" className={styles.intro}>
        <div>
          <p>{t('budget.monthly.eyebrow')}</p>
          <h2 id="monthly-budget-title">
            {t('budget.monthly.title', { month: lowerFirst(monthLabel, i18n.language) })}
          </h2>
          <span>{t('budget.monthly.description')}</span>
        </div>
        <div className={styles.actions}>
          <button
            className="primary-action"
            disabled={!ledger.data?.actionsAllowed}
            onClick={() => setDraft({ kind: 'expense', row: null })}
            title={
              ledger.data && !ledger.data.actionsAllowed
                ? t('budget.monthly.closedReason')
                : undefined
            }
            type="button"
          >
            {t('budget.monthly.createTransaction')}
          </button>
          <a
            className="secondary-action"
            href="/budget/plans"
            onClick={(event) => handleClientNavigation(event, '/budget/plans')}
          >
            {t('budget.monthly.managePlans')}
          </a>
        </div>
      </section>

      <MonthNavigator
        axis={axis}
        currentMonth={currentMonth}
        firstDataMonth={firstDataMonth}
        month={month}
      />

      {ledger.isPending ? (
        <section aria-busy="true" className={`card ${styles.routeError}`} role="status">
          <h2>{t('budget.monthly.loading')}</h2>
        </section>
      ) : ledger.isError ? (
        <section className={`card ${styles.routeError}`} role="alert">
          <h2>{t('budget.monthly.error.title')}</h2>
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

function lowerFirst(value: string, locale: string) {
  return value.charAt(0).toLocaleLowerCase(locale) + value.slice(1);
}
