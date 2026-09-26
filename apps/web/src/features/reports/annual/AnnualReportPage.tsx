import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { ReportsTabs } from '@/features/reports/ReportsTabs';
import { AggregateExplainModal } from './AggregateExplainModal';
import { AnnualCharts } from './AnnualCharts';
import { AnnualMonthCards } from './AnnualMonthCards';
import { AnnualReportTable } from './AnnualReportTable';
import { AnnualReportToolbar } from './AnnualReportToolbar';
import { ColumnPickerModal } from './ColumnPickerModal';
import { MixedPolicyBanner } from './MixedPolicyBanner';
import {
  AnnualRequestError,
  useAnnualReport,
  useAnnualReportPreferences,
  useSaveAnnualReportPreferences,
} from './useAnnualReport';
import styles from './AnnualReportPage.module.css';

interface AnnualReportPageProps {
  /** Workspace-local date, `YYYY-MM-DD`. */
  today: string;
  year: number;
}

export function AnnualReportPage({ today, year }: AnnualReportPageProps) {
  const { t } = useTranslation();
  const currentYear = Number(today.slice(0, 4));
  const report = useAnnualReport(year);
  const preferences = useAnnualReportPreferences();
  const save = useSaveAnnualReportPreferences();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [explained, setExplained] = useState<string | null>(null);
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

  let content: ReactNode;
  if (report.isPending) {
    content = (
      <section aria-busy="true" className={`card ${styles.state}`} role="status">
        <h2>{t('reports.loading')}</h2>
      </section>
    );
  } else if (report.isError) {
    const status = report.error instanceof AnnualRequestError ? report.error.status : 0;
    content = (
      <section className={`card ${styles.state}`} role="alert">
        <h2>
          {status === 422
            ? t('reports.annual.invalidYear')
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
  } else if (
    report.data.quality === 'EMPTY' &&
    report.data.rows.every((row) => row.state !== 'COMPLETE' && row.state !== 'PROVISIONAL')
  ) {
    content = (
      <section className={`card ${styles.state}`}>
        <h2>{t('reports.annual.empty', { year })}</h2>
      </section>
    );
  } else {
    const explainedColumn = report.data.columns.find((column) => column.id === explained);
    content = (
      <>
        {report.data.metricPolicy.state === 'MIXED' ? (
          <MixedPolicyBanner policy={report.data.metricPolicy} />
        ) : null}
        <AnnualReportTable onExplain={setExplained} report={report.data} />
        <AnnualMonthCards onExplain={setExplained} report={report.data} />
        <AnnualCharts report={report.data} />
        {explainedColumn ? (
          <AggregateExplainModal
            assetCode={explainedColumn.assetCode}
            close={() => setExplained(null)}
            columnId={explainedColumn.id}
            columnLabel={explainedColumn.label}
            year={year}
          />
        ) : null}
      </>
    );
  }

  return (
    <div className={styles.page}>
      <ReportsTabs active="annual" currentYear={currentYear} />
      <AnnualReportToolbar
        currentYear={currentYear}
        includeIncomplete={preferences.data?.incompleteMonths === 'include'}
        onOpenColumns={() => setPickerOpen(true)}
        onToggleIncomplete={toggleIncomplete}
        saving={save.isPending || !preferences.data}
        year={year}
      />
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
