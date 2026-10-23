import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { clampMonth } from '@/lib/monthBounds';
import { monthHref, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import styles from './MonthNavigator.module.css';

interface MonthNavigatorProps {
  axis: BudgetAxis | null;
  currentMonth: string;
  /** Oldest month with eligible workspace data; null when the workspace has none. */
  firstDataMonth?: string | null;
  month: string;
  onRetry?: () => void;
  /** Bounds query state: controls stay disabled until it is `ready`. */
  status?: 'loading' | 'error' | 'ready';
}

export function MonthNavigator({
  axis,
  currentMonth,
  firstDataMonth = null,
  month,
  onRetry,
  status = 'ready',
}: MonthNavigatorProps) {
  const { t, i18n } = useTranslation();
  const selectedYear = Number.parseInt(month.slice(0, 4), 10);
  const selectedMonthNumber = month.slice(5, 7);
  const currentYear = Number.parseInt(currentMonth.slice(0, 4), 10);
  // Years before the first transaction have nothing to show; the selected year stays listed
  // so a deep link never leaves the select without its own value.
  const firstYear = Math.min(
    firstDataMonth === null ? currentYear : Number.parseInt(firstDataMonth.slice(0, 4), 10),
    selectedYear,
  );
  const ready = status === 'ready';
  // An empty workspace has no history: only the current month is offered.
  const earliestMonth = firstDataMonth ?? currentMonth;
  const years = Array.from(
    { length: currentYear - firstYear + 1 },
    (_, index) => currentYear - index,
  );

  function chooseYear(year: number) {
    const candidate = `${year}-${selectedMonthNumber}`;
    const destination = clampMonth(candidate, earliestMonth, currentMonth);
    const href = monthHref(destination, axis);
    window.history.pushState({}, '', href);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  return (
    <section className={styles.navigator} aria-label={t('budget.monthly.months')}>
      <label className={styles.year}>
        <select
          aria-label={t('budget.monthly.year')}
          disabled={!ready}
          onChange={(event) => chooseYear(Number.parseInt(event.target.value, 10))}
          value={selectedYear}
        >
          {years.map((year) => (
            <option key={year} value={year}>
              {year}
            </option>
          ))}
        </select>
      </label>

      <nav aria-label={t('budget.monthly.months')} className={styles.tabs}>
        {Array.from({ length: 12 }, (_, index) => {
          const monthNumber = String(index + 1).padStart(2, '0');
          const candidate = `${selectedYear}-${monthNumber}`;
          const label = new Intl.DateTimeFormat(i18n.language, {
            month: 'long',
            timeZone: 'UTC',
          }).format(new Date(Date.UTC(2020, index, 1)));
          const active = candidate === month;
          const future = candidate > currentMonth;
          const beforeHistory = ready && candidate < earliestMonth;
          // The selected month stays reachable so a deep link never lands on a dead tab.
          const disabled = !ready || future || (beforeHistory && !active);
          const reason = beforeHistory && !future ? t('budget.monthly.beforeHistory') : undefined;

          return disabled ? (
            <button
              aria-label={
                reason === undefined
                  ? undefined
                  : `${capitalise(label, i18n.language)}, ${reason.toLowerCase()}`
              }
              className={styles.disabled}
              disabled
              key={candidate}
              title={reason}
              type="button"
            >
              {capitalise(label, i18n.language)}
            </button>
          ) : (
            <a
              aria-current={active ? 'page' : undefined}
              ref={active ? revealInStrip : undefined}
              className={active ? styles.active : undefined}
              href={monthHref(candidate, axis)}
              key={candidate}
              onClick={(event) => handleClientNavigation(event, monthHref(candidate, axis))}
            >
              {capitalise(label, i18n.language)}
            </a>
          );
        })}
      </nav>

      {status === 'error' ? (
        <div className={styles.retry} role="alert">
          <span>{t('budget.monthly.boundsError')}</span>
          <button className="secondary-action" onClick={onRetry} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
    </section>
  );
}

/** The strip scrolls on a narrow screen: bring the selected month into view, not the page. */
function revealInStrip(link: HTMLAnchorElement | null) {
  link?.scrollIntoView?.({ block: 'nearest', inline: 'center' });
}

function capitalise(value: string, locale: string) {
  return value.charAt(0).toLocaleUpperCase(locale) + value.slice(1);
}
