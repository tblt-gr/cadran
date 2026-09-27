import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useQueries } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { AnnualRequestError } from '@/features/reports/annual/useAnnualReport';
import { annualReportQueryOptions } from '@/features/reports/annual/useAnnualReport';
import { ContinuousDetailedReport } from './ContinuousDetailedReport';
import styles from './DetailedAnnualReports.module.css';

interface DetailedAnnualReportsProps {
  onReportsReady: (reports: AnnualReport[] | null) => void;
  report: MultiYearReport;
}

/** Reuses annual endpoints, then flattens their server-supplied monthly rows into one chronology. */
export function DetailedAnnualReports({ onReportsReady, report }: DetailedAnnualReportsProps) {
  const { t } = useTranslation();
  const results = useQueries({
    queries: report.years.map((year) => annualReportQueryOptions(year.year)),
  });
  const annualReports = results.flatMap((result) => (result.data ? [result.data] : []));
  const complete = annualReports.length === report.years.length;

  useEffect(() => {
    onReportsReady(complete ? annualReports : null);
  }, [annualReports, complete, onReportsReady]);

  if (results.some((result) => result.isPending)) {
    return (
      <section aria-busy="true" className={`card ${styles.state}`} role="status">
        <h2>{t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}</h2>
        <p>{t('reports.loading')}</p>
      </section>
    );
  }

  if (!complete) {
    return (
      <div className={styles.reports}>
        {report.years.map((year, index) => {
          const result = results[index]!;
          if (result.data) return null;
          const status = result.error instanceof AnnualRequestError ? result.error.status : 0;
          const unauthorized = status === 401 || status === 403;
          return (
            <section className={`card ${styles.state}`} key={year.year} role="alert">
              <h2>{t('reports.annual.caption', { year: year.year })}</h2>
              <p>{unauthorized ? t('reports.unauthorized') : t('reports.error')}</p>
              {!unauthorized ? (
                <button
                  className="secondary-action"
                  onClick={() => void result.refetch()}
                  type="button"
                >
                  {t('reports.retry')}
                </button>
              ) : null}
            </section>
          );
        })}
      </div>
    );
  }

  return (
    <div className={styles.reports}>
      <ContinuousDetailedReport annualReports={annualReports} report={report} />
    </div>
  );
}
