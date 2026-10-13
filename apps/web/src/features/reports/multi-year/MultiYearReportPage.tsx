import type { AnnualReport } from '@cadran/api-client';
import { Suspense, useCallback, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { Toast } from '@/components/ui/toast/Toast';
import { lazyComponent } from '@/lib/lazyComponent';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { ReportsTabs } from '@/features/reports/ReportsTabs';
import { AnnualTableActions } from '@/features/reports/annual/AnnualTableActions';
import { ColumnPickerModal } from '@/features/reports/annual/ColumnPickerModal';
import {
  AnnualRequestError,
  useAnnualReportPreferences,
  useSaveAnnualReportPreferences,
} from '@/features/reports/annual/useAnnualReport';
import { MultiYearCards } from './MultiYearCards';
import { DetailedAnnualReports } from './DetailedAnnualReports';
import { MultiYearSkeleton } from './MultiYearSkeleton';
import { MultiYearTable } from './MultiYearTable';
import { MultiYearRequestError, useMultiYearReport } from './useMultiYearReport';
import { YearRangeSelector, type MultiYearDisplayMode } from './YearRangeSelector';
import styles from './MultiYearReportPage.module.css';

// recharts loads on demand, after the report's own figures.
const MultiYearCharts = lazyComponent(() => import('./MultiYearCharts'), 'MultiYearCharts');

interface MultiYearReportPageProps {
  /** Workspace-local date, `YYYY-MM-DD`. */
  today: string;
}

export function MultiYearReportPage({ today }: MultiYearReportPageProps) {
  const { t } = useTranslation();
  const currentYear = Number(today.slice(0, 4));
  const [range, setRange] = useState<{ from: number; to: number } | null>(null);
  const [display, setDisplay] = useState<MultiYearDisplayMode>('compact');
  const [detailedReports, setDetailedReports] = useState<AnnualReport[] | null>(null);
  const preferences = useAnnualReportPreferences();
  const save = useSaveAnnualReportPreferences();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  function toggleIncomplete() {
    if (!preferences.data) return;
    save.mutate(
      {
        columns: preferences.data.columns,
        incompleteMonths: preferences.data.incompleteMonths === 'include' ? 'exclude' : 'include',
        version: preferences.data.version,
      },
      {
        onError: (error) => {
          if (error instanceof AnnualRequestError && error.status === 409) {
            setNotice(t('reports.annual.conflict'));
          }
        },
      },
    );
  }
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
  useHeaderTitle('/reports/all-years', title);

  let content: ReactNode;
  if (report.isPending) {
    content = <MultiYearSkeleton currentYear={currentYear} range={effectiveRange} />;
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
      <YearRangeSelector
        error={t('reports.multiYear.invalidRange')}
        from={range?.from ?? boundsForRangeError!.from}
        maxYear={currentYear}
        minYear={boundsForRangeError!.availableFrom ?? boundsForRangeError!.from}
        onChange={(nextRange) => {
          setDetailedReports(null);
          setRange(nextRange);
        }}
        to={range?.to ?? boundsForRangeError!.to}
      />
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
        <YearRangeSelector
          display={display}
          from={range?.from ?? report.data.from}
          maxYear={currentYear}
          minYear={bounds.data?.availableFrom ?? report.data.availableFrom ?? report.data.from}
          onChange={(nextRange) => {
            setDetailedReports(null);
            setRange(nextRange);
          }}
          onDisplayChange={(next) => {
            setDetailedReports(null);
            setDisplay(next);
          }}
          to={range?.to ?? report.data.to}
        />
        <AnnualTableActions
          includeIncomplete={preferences.data?.incompleteMonths === 'include'}
          onOpenColumns={() => setPickerOpen(true)}
          onToggleIncomplete={toggleIncomplete}
          saving={save.isPending || !preferences.data}
        />
        {display === 'compact' ? (
          <>
            <MultiYearTable report={report.data} />
            <MultiYearCards report={report.data} />
            <Suspense fallback={<SkeletonChartArea />}>
              <MultiYearCharts report={report.data} />
            </Suspense>
          </>
        ) : (
          <>
            <DetailedAnnualReports onReportsReady={setReportsReady} report={report.data} />
            {detailedReports ? (
              <Suspense fallback={<SkeletonChartArea />}>
                <MultiYearCharts annualReports={detailedReports} report={report.data} />
              </Suspense>
            ) : null}
          </>
        )}
      </>
    );
  }

  return (
    <div className={styles.page}>
      <ReportsTabs active="allYears" currentYear={currentYear} />
      {content}
      {pickerOpen && preferences.data ? (
        <ColumnPickerModal
          close={() => setPickerOpen(false)}
          onConflict={() => setNotice(t('reports.annual.conflict'))}
          preferences={preferences.data}
        />
      ) : null}
      {notice ? <Toast onDismiss={() => setNotice(null)}>{notice}</Toast> : null}
    </div>
  );
}
