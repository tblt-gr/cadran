import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { AnnualCellValue } from './AnnualCellValue';
import { drillDownHref, SUMMARY_ROW_IDS, summaryFigure } from './annualColumns';
import { isEmptyState, monthLabel } from './annualRowLabels';
import styles from './AnnualReportTable.module.css';

interface AnnualReportTableProps {
  onExplain: (columnId: string) => void;
  report: AnnualReport;
}

export function AnnualReportTable({ onExplain, report }: AnnualReportTableProps) {
  const { t, i18n } = useTranslation();

  return (
    <section aria-labelledby="annual-table-title" className={`card ${styles.wrap}`}>
      <h2 className={styles.title} id="annual-table-title">
        {t('reports.annual.caption', { year: report.year })}
      </h2>
      <div className={styles.scroll}>
        <table aria-labelledby="annual-table-title" className={styles.table}>
          <thead>
            <tr>
              <th scope="col">{t('reports.annual.month')}</th>
              {report.columns.map((column) => (
                <th key={column.id} scope="col">
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {report.rows.map((row) => (
              <tr className={isEmptyState(row.state) ? styles.dimmed : undefined} key={row.month}>
                <th scope="row">
                  {monthLabel(row.month, i18n.language)}
                  {row.state === 'PROVISIONAL' ? (
                    <small> {t('reports.annual.provisional')}</small>
                  ) : null}
                </th>
                {report.columns.map((column) => {
                  const cell = row.cells[column.id];
                  return (
                    <td key={column.id}>
                      {row.state === 'FUTURE' ? (
                        <EmptyValue label={t('reports.annual.future')} />
                      ) : row.state === 'NO_DATA' ? (
                        <EmptyValue label={t('reports.annual.noData')} />
                      ) : (
                        <AnnualCellValue
                          assetCode={column.assetCode}
                          href={drillDownHref(column.id, row.month)}
                          kind={column.kind}
                          reason={cell?.reason ?? null}
                          value={cell?.value ?? null}
                        />
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
          <tfoot>
            {SUMMARY_ROW_IDS.map((rowId) => (
              <tr key={rowId}>
                <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                {report.columns.map((column) => {
                  const aggregate = report.aggregates[column.id];
                  if (!aggregate) return <td key={column.id} />;
                  const figure = summaryFigure(aggregate, rowId);
                  return (
                    <td key={column.id}>
                      <AnnualCellValue
                        assetCode={column.assetCode}
                        kind={column.kind}
                        reason={figure.reason}
                        value={figure.value}
                      />
                      {figure.month ? (
                        <small> ({monthLabel(figure.month, i18n.language)})</small>
                      ) : null}
                      {rowId === 'total' ? (
                        <InfoButton
                          aria-haspopup="dialog"
                          label={t('reports.annual.explainColumn', { column: column.label })}
                          onClick={() => onExplain(column.id)}
                        />
                      ) : null}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tfoot>
        </table>
      </div>
    </section>
  );
}
