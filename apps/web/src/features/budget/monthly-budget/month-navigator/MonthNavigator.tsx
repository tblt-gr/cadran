import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { monthHref, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import styles from './MonthNavigator.module.css';

interface MonthNavigatorProps {
  axis: BudgetAxis | null;
  currentMonth: string;
  month: string;
}

const FIRST_YEAR = 2000;

export function MonthNavigator({ axis, currentMonth, month }: MonthNavigatorProps) {
  const { t, i18n } = useTranslation();
  const selectedYear = Number.parseInt(month.slice(0, 4), 10);
  const selectedMonthNumber = month.slice(5, 7);
  const currentYear = Number.parseInt(currentMonth.slice(0, 4), 10);
  const years = Array.from(
    { length: currentYear - FIRST_YEAR + 1 },
    (_, index) => currentYear - index,
  );

  function chooseYear(year: number) {
    const candidate = `${year}-${selectedMonthNumber}`;
    const destination = candidate > currentMonth ? currentMonth : candidate;
    const href = monthHref(destination, axis);
    window.history.pushState({}, '', href);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }

  return (
    <section className={styles.navigator} aria-label={t('budget.monthly.months')}>
      <label className={styles.year}>
        <span>{t('budget.monthly.year')}</span>
        <select
          aria-label={t('budget.monthly.year')}
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
          const disabled = candidate > currentMonth;
          const active = candidate === month;

          return disabled ? (
            <button className={styles.disabled} disabled key={candidate} type="button">
              {capitalise(label, i18n.language)}
            </button>
          ) : (
            <a
              aria-current={active ? 'page' : undefined}
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
    </section>
  );
}

function capitalise(value: string, locale: string) {
  return value.charAt(0).toLocaleUpperCase(locale) + value.slice(1);
}
