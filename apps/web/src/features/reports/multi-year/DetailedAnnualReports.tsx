import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useQueries } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { AnnualRequestError } from '@/features/reports/annual/useAnnualReport';
import { annualReportQueryOptions } from '@/features/reports/annual/useAnnualReport';
import { ContinuousDetailedReport } from './ContinuousDetailedReport';
import { DetailedReportSkeleton } from './DetailedReportSkeleton';
import styles from './DetailedAnnualReports.module.css';

interface DetailedAnnualReportsProps {
  expanded: boolean;
  showChanges: boolean;
  onReportsReady: (reports: AnnualReport[] | null, failed: boolean) => void;
  report: MultiYearReport;
}

/** Reuses annual endpoints, then flattens their server-supplied monthly rows into one chronology. */
export function DetailedAnnualReports({
  expanded,
  onReportsReady,
  report,
  showChanges,
}: DetailedAnnualReportsProps) {
  const { t } = useTranslation();
  const results = useQueries({
    queries: report.years.map((year) => annualReportQueryOptions(year.year, 'exclude')),
  });
  const annualReports = results.flatMap((result) => (result.data ? [result.data] : []));
  const complete = annualReports.length === report.years.length;
  const failed = !complete && results.some((result) => result.isError);

  useEffect(() => {
    onReportsReady(complete ? annualReports : null, failed);
  }, [annualReports, complete, failed, onReportsReady]);

  if (results.some((result) => result.isPending)) {
    return <DetailedReportSkeleton report={report} />;
  }

  if (!complete) {
    return (
      <div className={styles.reports}>
        {report.years.map((year, index) => {
          const result = results[index]!;
          if (result.data) return null;
          const unauthorized =
            result.error instanceof AnnualRequestError && result.error.kind === 'unauthorized';
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
      <ContinuousDetailedReport
        annualReports={annualReports}
        initiallyExpanded={expanded}
        key={String(expanded)}
        report={report}
        showChanges={showChanges}
      />
    </div>
  );
}
