import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { isEmptyState, monthLabel } from '@/features/reports/annual/annualRowLabels';
import { annualCellHref } from '@/features/reports/annual/annualColumns';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { useAnnualColumnLabel } from '@/features/reports/annual/columnLabel';
import { MonthLink } from '@/features/reports/annual/month-link/MonthLink';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import type { DetailedReportViewProps } from './detailedReportProps';
import { DetailedAggregateFigure } from './DetailedAggregateFigure';
import { MultiYearCellValue } from './MultiYearCellValue';
import {
  MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS,
  multiYearDetailedAggregateFigure,
} from './multiYearDetailedAggregate';
import { multiYearYearNotes } from './multiYearYearNotes';
import { YearNotes } from './YearNotes';
import { YearToggle } from './YearToggle';

interface DetailedCardsProps extends DetailedReportViewProps {
  /** The months of every year, the first year without its empty leading months. */
  rows: {
    annualReport: DetailedReportViewProps['annualReports'][number];
    row: DetailedReportViewProps['annualReports'][number]['rows'][number];
  }[];
}

/** The chronology as cards for narrow screens: a card per year and per open month, then the summary. */
export function DetailedCards({
  annualReports,
  columns,
  marksOf,
  onExplain,
  onToggleYear,
  opened,
  report,
  rows,
  showChanges,
}: DetailedCardsProps) {
  const { t, i18n } = useTranslation();
  const labelOf = useAnnualColumnLabel();

  return (
    <ul
      aria-label={t('reports.multiYear.detailedCardsLabel', { from: report.from, to: report.to })}
      className={cardStyles.list}
    >
      {annualReports.map((annualReport, groupIndex) => {
        const year = report.years[groupIndex];
        const label = year?.year ?? annualReport.year;
        const open = opened.has(label);
        const monthRows = rows.filter((item) => item.annualReport === annualReport);
        return (
          <Fragment key={label}>
            <li className={`card ${cardStyles.card}`}>
              <h3>
                <YearToggle label={label} onToggle={onToggleYear} open={open} />
              </h3>
              {year && multiYearYearNotes(year).length > 0 ? (
                <p>
                  <YearNotes year={year} />
                </p>
              ) : null}
              {year ? (
                <dl>
                  {columns.map((column) => {
                    const cell = year.cells[column.id];
                    return cell ? (
                      <div key={column.id}>
                        <dt>
                          <ColumnHeader column={column} />
                        </dt>
                        <dd>
                          <MultiYearCellValue
                            assetCode={column.assetCode}
                            cell={cell}
                            kind={column.kind}
                            showChange={showChanges}
                          />
                        </dd>
                      </div>
                    ) : null;
                  })}
                </dl>
              ) : null}
            </li>
            {open
              ? monthRows.map(({ row }) => (
                  <li
                    className={`card ${cardStyles.card} ${isEmptyState(row.state) ? cardStyles.dimmed : ''}`}
                    key={`${annualReport.year}-${row.month}`}
                  >
                    <h3>
                      <MonthLink month={row.month}>
                        {monthLabel(row.month, i18n.language)} {annualReport.year}
                      </MonthLink>
                    </h3>
                    {row.state === 'FUTURE' ? (
                      <p>
                        <EmptyValue quiet label={t('reports.annual.future')} />
                      </p>
                    ) : row.state === 'NO_DATA' ? (
                      <p>
                        <EmptyValue quiet label={t('reports.annual.noData')} />
                      </p>
                    ) : (
                      <dl>
                        {columns.map((column) => {
                          const marks = marksOf('months', row.month, column.id);
                          return (
                            <div key={column.id}>
                              <dt>
                                <ColumnHeader column={column} />
                              </dt>
                              <dd>
                                <GoalMarkedValue items={marks}>
                                  <AnnualCellValue
                                    assetCode={column.assetCode}
                                    href={annualCellHref(column.id, row.month)}
                                    kind={column.kind}
                                    reason={row.cells[column.id]?.reason ?? null}
                                    value={row.cells[column.id]?.value ?? null}
                                  />
                                </GoalMarkedValue>
                                <GoalMark items={marks} period={row.month} />
                              </dd>
                            </div>
                          );
                        })}
                      </dl>
                    )}
                  </li>
                ))
              : null}
          </Fragment>
        );
      })}
      <li className={`card ${cardStyles.card}`}>
        <h3>{t('reports.multiYear.detailedSummaryCard')}</h3>
        {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
          <dl key={rowId}>
            <dt>{t(`reports.annual.summary.${rowId}`)}</dt>
            {columns.map((column) => {
              const aggregate = report.detailedPeriod[column.id];
              if (!aggregate) return null;
              const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
              return (
                <dd key={column.id}>
                  {labelOf(column)}
                  {': '}
                  <DetailedAggregateFigure
                    column={column}
                    figure={figure}
                    onExplain={onExplain}
                    rowId={rowId}
                  />
                </dd>
              );
            })}
          </dl>
        ))}
      </li>
    </ul>
  );
}
