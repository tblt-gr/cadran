import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useQueries } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { AnnualRequestError } from '@/features/reports/annual/useAnnualReport';
import { annualReportQueryOptions } from '@/features/reports/annual/useAnnualReport';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
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
      <section
        aria-busy="true"
        className={`card ${styles.state}`}
        data-testid="detailed-reports-skeleton"
      >
        <SkeletonStatus>{t('reports.loading')}</SkeletonStatus>
        <h2>{t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}</h2>
        <div className={styles.tableScroll}>
          <table className={tableStyles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
              </tr>
            </thead>
            <SkeletonTableRows columns={6} rows={12} />
          </table>
        </div>
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
