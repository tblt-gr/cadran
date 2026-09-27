import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { ReportsTabs } from './ReportsTabs';
import { MonthlyKpiTable } from './MonthlyKpiTable';
import { MonthlyMonthNavigator, formatMonthLabel } from './MonthlyMonthNavigator';
import styles from './ReportsPage.module.css';
import { useMonthlyReport } from './useMonthlyReport';

/** A drill-down from the annual report lands here with the month in the query string. */
function initialMonth(fallback: string) {
  const requested = new URLSearchParams(window.location.search).get('month');
  return requested !== null && /^\d{4}-(0[1-9]|1[0-2])$/.test(requested) ? requested : fallback;
}

export function ReportsPage() {
  const { t, i18n } = useTranslation();
  const workspaceTimeZone = useWorkspaceTimeZone();
  const todayMonth = workspaceToday(new Date(), workspaceTimeZone).slice(0, 7);
  const [month, setMonth] = useState(() => initialMonth(todayMonth));
  const report = useMonthlyReport(month);
  useHeaderTitle(
    '/reports',
    t('reports.monthlyTitle', { month: formatMonthLabel(month, i18n.language) }),
  );
  let content: ReactNode;
  if (report.isPending) {
    content = (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('reports.loading')}</h2>
      </section>
    );
  } else if (report.isError) {
    const unauthorized = report.error.message === '401' || report.error.message === '403';
    content = (
      <section className={`card ${styles.state}`} role="alert">
        <h2>{t(unauthorized ? 'reports.unauthorized' : 'reports.error')}</h2>
        {!unauthorized ? (
          <button className="secondary-action" type="button" onClick={() => void report.refetch()}>
            {t('reports.retry')}
          </button>
        ) : null}
      </section>
    );
  } else if (!report.data) {
    content = (
      <section className={`card ${styles.state}`}>
        <h2>{t('reports.empty')}</h2>
      </section>
    );
  } else if (report.data.state === 'EMPTY') {
    content = (
      <>
        <section className={`card ${styles.state}`}>
          <h2>{t('reports.empty')}</h2>
        </section>
        <MonthlyKpiTable report={report.data} />
      </>
    );
  } else {
    content = <MonthlyKpiTable report={report.data} />;
  }
  return (
    <div className={styles.page}>
      <ReportsTabs active="monthly" currentYear={Number(todayMonth.slice(0, 4))} />
      <MonthlyMonthNavigator currentMonth={todayMonth} month={month} onSelect={setMonth} />
      {content}
    </div>
  );
}
