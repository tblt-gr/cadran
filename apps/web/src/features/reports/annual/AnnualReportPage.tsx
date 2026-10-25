import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { AggregateExplainModal } from './AggregateExplainModal';
import { AnnualCharts } from './AnnualCharts';
import { AnnualMonthCards } from './AnnualMonthCards';
import { AnnualReportTable } from './AnnualReportTable';
import { AnnualReportPageSkeleton } from './AnnualReportPageSkeleton';
import { MixedPolicyBanner } from './MixedPolicyBanner';
import { useAnnualColumnLabel } from './columnLabel';
import { AnnualRequestError, useAnnualReport } from './useAnnualReport';
import styles from './AnnualReportPage.module.css';

interface AnnualReportPageProps {
  year: number;
}

export function AnnualReportPage({ year }: AnnualReportPageProps) {
  const { t } = useTranslation();
  const labelOf = useAnnualColumnLabel();
  const report = useAnnualReport(year);
  const [explained, setExplained] = useState<string | null>(null);
  useHeaderTitle(`/budget/${year}`, t('reports.annual.caption', { year }));

  let content: ReactNode;
  if (report.isPending) {
    content = <AnnualReportPageSkeleton status={t('reports.loading')} year={year} />;
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
        <AnnualReportTable onExplain={setExplained} report={report.data} />
        <AnnualMonthCards onExplain={setExplained} report={report.data} />
        {report.data.metricPolicy.state === 'MIXED' ? (
          <MixedPolicyBanner policy={report.data.metricPolicy} />
        ) : null}
        <AnnualCharts report={report.data} />
        {explainedColumn ? (
          <AggregateExplainModal
            assetCode={explainedColumn.assetCode}
            close={() => setExplained(null)}
            columnId={explainedColumn.id}
            columnLabel={labelOf(explainedColumn)}
            year={year}
          />
        ) : null}
      </>
    );
  }

  return <div className={styles.page}>{content}</div>;
}
