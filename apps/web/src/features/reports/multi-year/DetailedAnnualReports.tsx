import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useQueries } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AggregateExplainModal } from '@/features/reports/annual/AggregateExplainModal';
import { AnnualCharts } from '@/features/reports/annual/AnnualCharts';
import { AnnualMonthCards } from '@/features/reports/annual/AnnualMonthCards';
import { AnnualReportTable } from '@/features/reports/annual/AnnualReportTable';
import { MixedPolicyBanner } from '@/features/reports/annual/MixedPolicyBanner';
import { AnnualRequestError } from '@/features/reports/annual/useAnnualReport';
import { annualReportQueryOptions } from '@/features/reports/annual/useAnnualReport';
import styles from './DetailedAnnualReports.module.css';

interface DetailedAnnualReportsProps {
  onReportsReady: (reports: AnnualReport[] | null) => void;
  report: MultiYearReport;
}

/** Reuses the annual endpoint and its presentation so exact monthly values never move into React. */
export function DetailedAnnualReports({ onReportsReady, report }: DetailedAnnualReportsProps) {
  const { t } = useTranslation();
  const [explained, setExplained] = useState<{ columnId: string; year: number } | null>(null);
  const results = useQueries({
    queries: report.years.map((year) => annualReportQueryOptions(year.year)),
  });
  const annualReports = results.flatMap((result) => (result.data ? [result.data] : []));

  useEffect(() => {
    onReportsReady(annualReports.length > 0 ? annualReports : null);
  }, [annualReports, onReportsReady]);

  return (
    <div className={styles.reports}>
      {report.years.map((year, index) => {
        const result = results[index]!;
        const annualReport = result.data;
        if (result.isPending) {
          return (
            <section
              aria-busy="true"
              className={`card ${styles.state}`}
              key={year.year}
              role="status"
            >
              <h2>{t('reports.annual.caption', { year: year.year })}</h2>
              <p>{t('reports.loading')}</p>
            </section>
          );
        }
        if (!annualReport) {
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
        }
        const explainedColumn =
          explained?.year === annualReport.year
            ? annualReport.columns.find((column) => column.id === explained.columnId)
            : null;
        return (
          <section aria-labelledby={`detailed-year-${annualReport.year}`} key={annualReport.year}>
            <h2 id={`detailed-year-${annualReport.year}`}>
              {t('reports.annual.caption', { year: annualReport.year })}
            </h2>
            {annualReport.metricPolicy.state === 'MIXED' ? (
              <MixedPolicyBanner policy={annualReport.metricPolicy} />
            ) : null}
            <AnnualReportTable
              onExplain={(columnId) => setExplained({ columnId, year: annualReport.year })}
              report={annualReport}
              titleId={`detailed-annual-table-${annualReport.year}`}
            />
            <AnnualMonthCards
              onExplain={(columnId) => setExplained({ columnId, year: annualReport.year })}
              report={annualReport}
            />
            <AnnualCharts includeTrends={false} report={annualReport} />
            {explainedColumn ? (
              <AggregateExplainModal
                assetCode={explainedColumn.assetCode}
                close={() => setExplained(null)}
                columnId={explainedColumn.id}
                columnLabel={explainedColumn.label}
                year={annualReport.year}
              />
            ) : null}
          </section>
        );
      })}
    </div>
  );
}
