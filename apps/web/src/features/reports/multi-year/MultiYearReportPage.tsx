import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ReportsTabs } from '@/features/reports/ReportsTabs';
import { MultiYearCards } from './MultiYearCards';
import { MultiYearCharts } from './MultiYearCharts';
import { MultiYearSkeleton } from './MultiYearSkeleton';
import { MultiYearTable } from './MultiYearTable';
import { NominalValuesNotice } from './NominalValuesNotice';
import { MultiYearRequestError, useMultiYearReport } from './useMultiYearReport';
import { YearRangeSelector } from './YearRangeSelector';
import styles from './MultiYearReportPage.module.css';

interface MultiYearReportPageProps {
  /** Workspace-local date, `YYYY-MM-DD`. */
  today: string;
}

const MAX_SPAN_YEARS = 15;

export function MultiYearReportPage({ today }: MultiYearReportPageProps) {
  const { t } = useTranslation();
  const currentYear = Number(today.slice(0, 4));
  const [range, setRange] = useState<{ from: number; to: number } | null>(null);
  // The unfiltered response carries `availableFrom`, the workspace's true first
  // data year. Its displayed `from` may be capped to the latest 15-year window,
  // so it must not become the selector's lower bound.
  const bounds = useMultiYearReport();
  const report = useMultiYearReport(range?.from, range?.to);
  const status = report.error instanceof MultiYearRequestError ? report.error.status : 0;
  // A range the selector itself cannot construct (see its own bounded options)
  // was still refused by the backend: the selector and its bounds are known, so
  // the error can stay inline next to it instead of replacing the whole screen.
  const boundsForRangeError = report.isError && status === 422 && bounds.data ? bounds.data : null;

  let content: ReactNode;
  if (report.isPending) {
    const years = range ? Math.min(range.to - range.from + 1, MAX_SPAN_YEARS) : 1;
    content = <MultiYearSkeleton years={years} />;
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
    content = (
      <>
        <NominalValuesNotice />
        <YearRangeSelector
          error={t('reports.multiYear.invalidRange')}
          from={range?.from ?? boundsForRangeError!.from}
          maxYear={currentYear}
          minYear={boundsForRangeError!.availableFrom ?? boundsForRangeError!.from}
          onChange={setRange}
          to={range?.to ?? boundsForRangeError!.to}
        />
      </>
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
        <NominalValuesNotice />
        <YearRangeSelector
          from={range?.from ?? report.data.from}
          maxYear={currentYear}
          minYear={bounds.data?.availableFrom ?? report.data.availableFrom ?? report.data.from}
          onChange={setRange}
          to={range?.to ?? report.data.to}
        />
        <MultiYearTable report={report.data} />
        <MultiYearCards report={report.data} />
        <MultiYearCharts report={report.data} />
      </>
    );
  }

  return (
    <div className={styles.page}>
      <ReportsTabs active="allYears" currentYear={currentYear} />
      {content}
    </div>
  );
}
