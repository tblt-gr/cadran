import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalMarks } from '@/features/reports/useGoalMarks';
import { AnnualCellValue } from './AnnualCellValue';
import {
  SUMMARY_ROW_IDS,
  annualCellHref,
  groupStartIds,
  summaryFigure,
  withoutLeadingEmptyMonths,
} from './annualColumns';
import { ColumnHeader } from './ColumnHeader';
import { useAnnualColumnLabel } from './columnLabel';
import { isEmptyState, monthLabel } from './annualRowLabels';
import styles from './AnnualReportTable.module.css';

interface AnnualReportTableProps {
  onExplain: (columnId: string) => void;
  titleId?: string;
  report: AnnualReport;
}

export function AnnualReportTable({
  onExplain,
  report,
  titleId = 'annual-table-title',
}: AnnualReportTableProps) {
  const { t, i18n } = useTranslation();
  const labelOf = useAnnualColumnLabel();
  const rows = withoutLeadingEmptyMonths(report.rows);
  const starts = groupStartIds(report.columns);
  const edge = (id: string) => (starts.has(id) ? styles.groupStart : undefined);
  // Monthly goals mark the month rows, yearly goals the total row. Cells stay as they are until
  // the evaluations arrive, and when they fail.
  const goals = useGoalMarks({
    from: `${report.year}-01`,
    to: `${report.year}-12`,
    granularity: 'BOTH',
  });

  return (
    <>
      <section aria-labelledby={titleId} className={`card ${styles.wrap}`}>
        <header className="sr-only">
          <h2 className={styles.title} id={titleId}>
            {t('reports.annual.caption', { year: report.year })}
          </h2>
        </header>
        <div className={styles.scroll}>
          <table aria-labelledby={titleId} className={styles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
                {report.columns.map((column) => (
                  <th className={edge(column.id)} key={column.id} scope="col">
                    <ColumnHeader column={column} />
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr className={isEmptyState(row.state) ? styles.dimmed : undefined} key={row.month}>
                  <th scope="row">{monthLabel(row.month, i18n.language)}</th>
                  {report.columns.map((column) => {
                    const cell = row.cells[column.id];
                    const marks = marksFor(goals.evaluations?.months, row.month, column.id);
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
              ))}
            </tbody>
            <tfoot>
              {SUMMARY_ROW_IDS.map((rowId) => (
                <tr key={rowId}>
                  <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                  {report.columns.map((column) => {
                    const aggregate = report.aggregates[column.id];
                    if (!aggregate) return <td className={edge(column.id)} key={column.id} />;
                    const figure = summaryFigure(aggregate, rowId);
                    const marks =
                      rowId === 'total'
                        ? marksFor(goals.evaluations?.years, String(report.year), column.id)
                        : [];
                    return (
                      <td className={edge(column.id)} key={column.id}>
                        <GoalMarkedValue items={marks}>
                          <AnnualCellValue
                            assetCode={column.assetCode}
                            kind={column.kind}
                            reason={figure.reason}
                            value={figure.value}
                          />
                        </GoalMarkedValue>
                        {figure.month ? (
                          <small> ({monthLabel(figure.month, i18n.language)})</small>
                        ) : null}
                        {/* A goal's `i` replaces the calculation one on a marked total. */}
                        {rowId === 'total' && figure.value !== null && marks.length === 0 ? (
                          <InfoButton
                            aria-haspopup="dialog"
                            className={styles.explain}
                            label={t('reports.annual.explainColumn', { column: labelOf(column) })}
                            onClick={() => onExplain(column.id)}
                          />
                        ) : null}
                        <GoalMark items={marks} period={String(report.year)} />
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
        {goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
      </section>
    </>
  );
}
