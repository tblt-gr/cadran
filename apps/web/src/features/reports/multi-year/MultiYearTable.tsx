import type { MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { MultiYearCellValue } from './MultiYearCellValue';
import { MULTI_YEAR_SUMMARY_ROW_IDS, multiYearSummaryFigure } from './multiYearSummary';
import { multiYearYearNotes } from './multiYearYearNotes';
import styles from './MultiYearTable.module.css';

interface MultiYearTableProps {
  report: MultiYearReport;
}

export function MultiYearTable({ report }: MultiYearTableProps) {
  const { t, i18n } = useTranslation();

  return (
    <section aria-labelledby="multi-year-table-title" className={`card ${styles.wrap}`}>
      <header className={styles.header}>
        <h2 className={styles.title} id="multi-year-table-title">
          {t('reports.multiYear.caption', { from: report.from, to: report.to })}
        </h2>
      </header>
      <div className={styles.scroll}>
        <table aria-labelledby="multi-year-table-title" className={styles.table}>
          <thead>
            <tr>
              <th scope="col">{t('reports.multiYear.year')}</th>
              {report.columns.map((column) => (
                <th key={column.id} scope="col">
                  <ColumnHeader column={column} />
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {report.years.map((year) => (
              <tr className={year.state === 'NO_DATA' ? styles.dimmed : undefined} key={year.year}>
                <th scope="row">
                  <a
                    href={`/budget/${year.year}`}
                    onClick={(event) => handleClientNavigation(event, `/budget/${year.year}`)}
                  >
                    {year.year}
                  </a>
                  {multiYearYearNotes(year).map((note) => (
                    <small className={styles.meta} key={note.key}>
                      {' '}
                      {t(note.key, note.params)}
                    </small>
                  ))}
                </th>
                {report.columns.map((column) => (
                  <td key={column.id}>
                    <MultiYearCellValue
                      assetCode={column.assetCode}
                      cell={year.cells[column.id]!}
                      kind={column.kind}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          <tfoot>
            {MULTI_YEAR_SUMMARY_ROW_IDS.map((rowId) => (
              <tr key={rowId}>
                <th scope="row">{t(`reports.multiYear.summary.${rowId}`)}</th>
                {report.columns.map((column) => {
                  const summary = report.summary[column.id];
                  if (!summary) return <td key={column.id} />;
                  const figure = multiYearSummaryFigure(summary, rowId);
                  return (
                    <td key={column.id}>
                      {figure.value === null ? (
                        <EmptyValue
                          quiet
                          label={t('reports.annual.notCalculable')}
                          reason={
                            figure.reason
                              ? t(`reports.multiYear.reasons.${figure.reason}`, {
                                  defaultValue: figure.reason,
                                })
                              : null
                          }
                        />
                      ) : (
                        <>
                          {formatAnnualValue(
                            figure.value,
                            column.kind,
                            column.assetCode,
                            i18n.language,
                          )}
                          {figure.year !== null ? <small> ({figure.year})</small> : null}
                        </>
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tfoot>
        </table>
      </div>
      <footer className={styles.notes}>
        <p>
          {t('reports.multiYear.policyNote', {
            versions: report.years
              .filter((year) => year.state !== 'NO_DATA')
              .map(
                (year) =>
                  `${year.year} ${
                    year.metricPolicy.state === 'MIXED'
                      ? t('reports.multiYear.mixedPolicy').toLowerCase()
                      : `v${year.metricPolicy.version}`
                  }`,
              )
              .join(', '),
          })}
        </p>
      </footer>
    </section>
  );
}
