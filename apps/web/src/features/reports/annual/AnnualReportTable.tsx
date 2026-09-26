import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
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
    <div className={`card ${styles.wrap}`}>
      <table className={styles.table}>
        <caption>{t('reports.annual.caption', { year: report.year })}</caption>
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
                      t('reports.annual.future')
                    ) : row.state === 'NO_DATA' ? (
                      t('reports.annual.noData')
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
                      <button
                        aria-label={t('reports.annual.explainColumn', { column: column.label })}
                        className="secondary-action"
                        onClick={() => onExplain(column.id)}
                        type="button"
                      >
                        {t('reports.explain')}
                      </button>
                    ) : null}
                  </td>
                );
              })}
            </tr>
          ))}
        </tfoot>
      </table>
    </div>
  );
}
