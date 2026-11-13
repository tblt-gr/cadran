import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalMarks } from '@/features/reports/useGoalMarks';
import { AnnualCellValue } from './AnnualCellValue';
import { SUMMARY_ROW_IDS, summaryFigure, withoutLeadingEmptyMonths } from './annualColumns';
import { AnnualMonthCard } from './AnnualMonthCard';
import { ColumnHeader } from './ColumnHeader';
import { useAnnualColumnLabel } from './columnLabel';
import { monthLabel } from './annualRowLabels';
import styles from './AnnualMonthCards.module.css';

interface AnnualMonthCardsProps {
  onExplain: (columnId: string) => void;
  report: AnnualReport;
}

/** Below 640 px the table becomes one card per month, then one summary card, with the same values. */
export function AnnualMonthCards({ onExplain, report }: AnnualMonthCardsProps) {
  const { t, i18n } = useTranslation();
  const labelOf = useAnnualColumnLabel();
  // The same query as the table: one request, one cache entry.
  const goals = useGoalMarks({
    from: `${report.year}-01`,
    to: `${report.year}-12`,
    granularity: 'BOTH',
  });

  return (
    <>
      <ul
        aria-label={t('reports.annual.cardsLabel', { year: report.year })}
        className={styles.list}
      >
        {withoutLeadingEmptyMonths(report.rows).map((row) => (
          <AnnualMonthCard
            columns={report.columns}
            key={row.month}
            months={goals.evaluations?.months}
            row={row}
          />
        ))}
        <li className={`card ${styles.card}`}>
          <h3>{t('reports.annual.summaryTitle')}</h3>
          {report.columns.map((column) => {
            const aggregate = report.aggregates[column.id];
            if (!aggregate) return null;
            return (
              <section key={column.id}>
                <h4>
                  <ColumnHeader column={column} />
                  {aggregate.total !== null &&
                  marksFor(goals.evaluations?.years, String(report.year), column.id).length ===
                    0 ? (
                    <InfoButton
                      aria-haspopup="dialog"
                      className={styles.explain}
                      label={t('reports.annual.explainColumn', { column: labelOf(column) })}
                      onClick={() => onExplain(column.id)}
                    />
                  ) : null}
                </h4>
                <dl>
                  {SUMMARY_ROW_IDS.map((rowId) => {
                    const figure = summaryFigure(aggregate, rowId);
                    const marks =
                      rowId === 'total'
                        ? marksFor(goals.evaluations?.years, String(report.year), column.id)
                        : [];
                    return (
                      <div key={rowId}>
                        <dt>{t(`reports.annual.summary.${rowId}`)}</dt>
                        <dd>
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
                          <GoalMark items={marks} period={String(report.year)} />
                        </dd>
                      </div>
                    );
                  })}
                </dl>
              </section>
            );
          })}
        </li>
      </ul>
      {goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
    </>
  );
}
