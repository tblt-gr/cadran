import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { MetricPolicyBadge } from '@/features/metric-policy/metric-policy-badge/MetricPolicyBadge';
import { ReportsTabs } from './ReportsTabs';
import { MonthlyKpiTable } from './MonthlyKpiTable';
import styles from './ReportsPage.module.css';
import { useMonthlyReport } from './useMonthlyReport';

function currentMonth() {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
}

/** A drill-down from the annual report lands here with the month in the query string. */
function initialMonth() {
  const requested = new URLSearchParams(window.location.search).get('month');
  return requested !== null && /^\d{4}-(0[1-9]|1[0-2])$/.test(requested)
    ? requested
    : currentMonth();
}

export function ReportsPage() {
  const { t } = useTranslation();
  const workspaceTimeZone = useWorkspaceTimeZone();
  const [month, setMonth] = useState(initialMonth);
  const report = useMonthlyReport(month);
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
        <p className={styles.summary} role="status">
          {t('reports.pending', { count: report.data.pendingCount })} · {t('reports.quality')}:{' '}
          {report.data.quality} · <MetricPolicyBadge policy={report.data.metricPolicy} />
        </p>
        <section className={`card ${styles.state}`}>
          <h2>{t('reports.empty')}</h2>
        </section>
        <MonthlyKpiTable report={report.data} />
      </>
    );
  } else {
    content = (
      <>
        <p className={styles.summary} role="status">
          {t('reports.pending', { count: report.data.pendingCount })} · {t('reports.quality')}:{' '}
          {report.data.quality} · <MetricPolicyBadge policy={report.data.metricPolicy} />
        </p>
        <MonthlyKpiTable report={report.data} />
      </>
    );
  }
  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <h1>{t('reports.title')}</h1>
        <label>
          {t('reports.month')}
          <input type="month" value={month} onChange={(event) => setMonth(event.target.value)} />
        </label>
      </header>
      <ReportsTabs
        active="monthly"
        currentYear={Number(workspaceToday(new Date(), workspaceTimeZone).slice(0, 4))}
      />
      {content}
    </div>
  );
}
