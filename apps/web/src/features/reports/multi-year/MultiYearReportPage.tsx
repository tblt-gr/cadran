import type { AnnualReport } from '@cadran/api-client';
import { Suspense, useCallback, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { lazyComponent } from '@/lib/lazyComponent';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { MultiYearCards } from './MultiYearCards';
import { DetailedAnnualReports } from './DetailedAnnualReports';
import { MultiYearChartsSkeleton, MultiYearSkeleton } from './MultiYearSkeleton';
import type { MultiYearDisplay } from '@/features/budget/year/yearViewSearch';
import { MultiYearTable } from './MultiYearTable';
import { MultiYearRequestError, useMultiYearReport } from './useMultiYearReport';

import styles from './MultiYearReportPage.module.css';

// recharts loads on demand, after the report's own figures.
const MultiYearCharts = lazyComponent(() => import('./MultiYearCharts'), 'MultiYearCharts');

interface MultiYearReportPageProps {
  /** Workspace-local year, the upper bound of the range. */
  display: MultiYearDisplay;
  /** Requested range from the URL; null lets the backend apply its own default bounds. */
  range: { from: number; to: number } | null;
  /** Year of the route, which keys the header title. */
  year: number;
}

export function MultiYearReportPage({ display, range, year }: MultiYearReportPageProps) {
  const { t } = useTranslation();
  const [detailedReports, setDetailedReports] = useState<AnnualReport[] | null>(null);

  const setReportsReady = useCallback((reports: AnnualReport[] | null) => {
    setDetailedReports((current) =>
      current !== null &&
      reports !== null &&
      current.length === reports.length &&
      current.every((item, index) => item === reports[index])
        ? current
        : reports,
    );
  }, []);
  const bounds = useMultiYearReport();
  const report = useMultiYearReport(range?.from, range?.to);
  const status = report.error instanceof MultiYearRequestError ? report.error.status : 0;
  // A range the selector itself cannot construct was still refused by the backend: the
  // toolbar selector stays usable, so the error is only a message, not a replaced screen.
  const boundsForRangeError = report.isError && status === 422 && bounds.data ? bounds.data : null;
  const effectiveRange =
    range ??
    (report.data ? { from: report.data.from, to: report.data.to } : null) ??
    (bounds.data ? { from: bounds.data.from, to: bounds.data.to } : null);
  const title =
    effectiveRange === null
      ? null
      : effectiveRange.from === effectiveRange.to
        ? `${t('reports.multiYear.title')} ${effectiveRange.from}`
        : t('reports.multiYear.caption', { from: effectiveRange.from, to: effectiveRange.to });
  useHeaderTitle(`/budget/${year}`, title);

  let content: ReactNode;
  if (report.isPending) {
    content = <MultiYearSkeleton range={effectiveRange} />;
  } else if (report.isError && boundsForRangeError === null) {
    content = (
      <section className={`card ${styles.state}`} role="alert">
        <h2>
          {status === 422
            ? t('reports.multiYear.invalidRange')
            : status === 401 || status === 403
              ? t('reports.unauthorized')
              : t('reports.error')}
        </h2>
        {status === 422 || status === 401 || status === 403 ? null : (
          <button className="secondary-action" onClick={() => void report.refetch()} type="button">
            {t('reports.retry')}
          </button>
        )}
      </section>
    );
  } else if (report.isError) {
    // The range selector in the toolbar stays usable: only the message sits here.
    content = (
      <section className={`card ${styles.state}`} role="alert">
        <h2>{t('reports.multiYear.invalidRange')}</h2>
      </section>
    );
  } else if (report.data.years.length === 0) {
    content = (
      <section className={`card ${styles.state}`}>
        <h2>{t('reports.multiYear.empty')}</h2>
      </section>
    );
  } else {
    content = (
      <>
        {display === 'compact' ? (
          <>
            <MultiYearTable report={report.data} />
            <MultiYearCards report={report.data} />
            <Suspense fallback={<MultiYearChartsSkeleton />}>
              <MultiYearCharts report={report.data} />
            </Suspense>
          </>
        ) : (
          <>
            <DetailedAnnualReports onReportsReady={setReportsReady} report={report.data} />
            {detailedReports ? (
              <Suspense fallback={<MultiYearChartsSkeleton />}>
                <MultiYearCharts annualReports={detailedReports} report={report.data} />
              </Suspense>
            ) : null}
          </>
        )}
      </>
    );
  }

  return <div className={styles.page}>{content}</div>;
}
