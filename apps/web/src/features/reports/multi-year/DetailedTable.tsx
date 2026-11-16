import { Fragment, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { isEmptyState, monthLabel } from '@/features/reports/annual/annualRowLabels';
import {
  annualCellHref,
  groupStartIds,
  withoutLeadingEmptyMonths,
} from '@/features/reports/annual/annualColumns';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { MonthLink } from '@/features/reports/annual/month-link/MonthLink';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import localStyles from './ContinuousDetailedReport.module.css';
import type { DetailedReportViewProps } from './detailedReportProps';
import { DetailedAggregateFigure } from './DetailedAggregateFigure';
import { MultiYearCellValue } from './MultiYearCellValue';
import {
  MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS,
  multiYearDetailedAggregateFigure,
} from './multiYearDetailedAggregate';
import { YearNotes } from './YearNotes';
import { YearToggle } from './YearToggle';

interface DetailedTableProps extends DetailedReportViewProps {
  /** Rendered last inside the card, under the table. */
  footer?: ReactNode;
}

/** The chronology as one table: a row per year, its months beneath when the year is open. */
export function DetailedTable({
  annualReports,
  columns,
  footer,
  marksOf,
  onExplain,
  onToggleYear,
  opened,
  report,
  showChanges,
}: DetailedTableProps) {
  const { t, i18n } = useTranslation();
  const starts = groupStartIds(columns);
  const edge = (id: string) => (starts.has(id) ? tableStyles.groupStart : undefined);
  const titleId = 'detailed-multi-year-table';

  return (
    <section aria-labelledby={titleId} className={`card ${tableStyles.wrap}`}>
      <header className="sr-only">
        <h2 className={tableStyles.title} id={titleId}>
          {t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}
        </h2>
      </header>
      <div
        aria-label={t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}
        className={`${tableStyles.scroll} ${localStyles.verticalScroll}`}
        role="region"
        tabIndex={0}
      >
        <table aria-labelledby={titleId} className={tableStyles.table}>
          <thead>
            <tr>
              <th scope="col">{t('reports.annual.month')}</th>
              {columns.map((column) => (
                <th className={edge(column.id)} key={column.id} scope="col">
                  <ColumnHeader column={column} />
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {annualReports.map((annualReport, groupIndex) => {
              const year = report.years[groupIndex];
              const label = year?.year ?? annualReport.year;
              const open = opened.has(label);
              const months =
                groupIndex === 0 ? withoutLeadingEmptyMonths(annualReport.rows) : annualReport.rows;
              return (
                <Fragment key={label}>
                  <tr className={localStyles.yearRow}>
                    <th scope="row">
                      <YearToggle label={label} onToggle={onToggleYear} open={open} />
                      {year ? <YearNotes year={year} /> : null}
                    </th>
                    {columns.map((column) => {
                      const cell = year?.cells[column.id];
                      const marks = marksOf('years', String(label), column.id);
                      return (
                        <td className={edge(column.id)} key={column.id}>
                          {cell ? (
                            <>
                              <GoalMarkedValue items={marks}>
                                <MultiYearCellValue
                                  assetCode={column.assetCode}
                                  cell={cell}
                                  kind={column.kind}
                                  showChange={showChanges}
                                />
                              </GoalMarkedValue>
                              <GoalMark items={marks} period={String(label)} />
                            </>
                          ) : null}
                        </td>
                      );
                    })}
                  </tr>
                  {open
                    ? months.map((row) => {
                        const isYearBoundary = row.month.endsWith('-12');
                        return (
                          <tr
                            className={[
                              isEmptyState(row.state) ? tableStyles.dimmed : '',
                              isYearBoundary ? localStyles.yearBoundary : '',
                            ]
                              .filter(Boolean)
                              .join(' ')}
                            key={`${annualReport.year}-${row.month}`}
                          >
                            <th scope="row">
                              <MonthLink month={row.month}>
                                {monthLabel(row.month, i18n.language)} {annualReport.year}
                              </MonthLink>
                            </th>
                            {columns.map((column) => {
                              const cell = row.cells[column.id];
                              const marks = marksOf('months', row.month, column.id);
                              return (
                                <td className={edge(column.id)} key={column.id}>
                                  {row.state === 'FUTURE' ? (
                                    <EmptyValue quiet label={t('reports.annual.future')} />
                                  ) : row.state === 'NO_DATA' ? (
                                    <EmptyValue quiet label={t('reports.annual.noData')} />
                                  ) : (
                                    <>
                                      <GoalMarkedValue items={marks}>
                                        <AnnualCellValue
                                          assetCode={column.assetCode}
                                          href={annualCellHref(column.id, row.month)}
                                          kind={column.kind}
                                          reason={cell?.reason ?? null}
                                          value={cell?.value ?? null}
                                        />
                                      </GoalMarkedValue>
                                      <GoalMark items={marks} period={row.month} />
                                    </>
                                  )}
                                </td>
                              );
                            })}
                          </tr>
                        );
                      })
                    : null}
                </Fragment>
              );
            })}
          </tbody>
          <tfoot>
            {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
              <tr key={rowId}>
                <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                {columns.map((column) => {
                  const aggregate = report.detailedPeriod[column.id];
                  if (!aggregate) return <td className={edge(column.id)} key={column.id} />;
                  const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
                  return (
                    <td className={edge(column.id)} key={column.id}>
                      <DetailedAggregateFigure
                        column={column}
                        figure={figure}
                        onExplain={onExplain}
                        rowId={rowId}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tfoot>
        </table>
      </div>
      {footer}
    </section>
  );
}
