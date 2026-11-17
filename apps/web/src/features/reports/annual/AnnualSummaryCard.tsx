import type { AnnualReport, GoalPeriodEvaluation } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { AnnualCellValue } from './AnnualCellValue';
import { SUMMARY_ROW_IDS, summaryFigure } from './annualColumns';
import { ColumnHeader } from './ColumnHeader';
import { useAnnualColumnLabel } from './columnLabel';
import { monthLabel } from './annualRowLabels';
import styles from './AnnualMonthCards.module.css';

interface AnnualSummaryCardProps {
  column: AnnualReport['columns'][number];
  onExplain: (columnId: string) => void;
  report: AnnualReport;
  years: readonly GoalPeriodEvaluation[] | undefined;
}

/** One column of the year summary, folded on its total like a month card. */
export function AnnualSummaryCard({ column, onExplain, report, years }: AnnualSummaryCardProps) {
  const { t, i18n } = useTranslation();
  const labelOf = useAnnualColumnLabel();
  const [open, setOpen] = useState(false);
  const aggregate = report.aggregates[column.id];
  if (!aggregate) return null;
  const period = String(report.year);
  const totalMarks = marksFor(years, period, column.id);
  const total = summaryFigure(aggregate, 'total');

  return (
    <li className={`card ${styles.card} ${styles.collapsible}`}>
      <Disclosure
        meta={
          <span className={styles.plainMeta}>
            <AnnualCellValue
              assetCode={column.assetCode}
              kind={column.kind}
              reason={total.reason}
              value={total.value}
            />
          </span>
        }
        onToggle={setOpen}
        open={open}
        spread
        title={
          <h4>
            <ColumnHeader column={column} />
          </h4>
        }
      >
        <dl>
          {SUMMARY_ROW_IDS.map((rowId) => {
            const figure = summaryFigure(aggregate, rowId);
            const marks = rowId === 'total' ? totalMarks : [];
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
                  <GoalMark items={marks} period={period} />
                  {rowId === 'total' && figure.value !== null && marks.length === 0 ? (
                    <InfoButton
                      aria-haspopup="dialog"
                      className={styles.explain}
                      label={t('reports.annual.explainColumn', { column: labelOf(column) })}
                      onClick={() => onExplain(column.id)}
                    />
                  ) : null}
                </dd>
              </div>
            );
          })}
        </dl>
      </Disclosure>
    </li>
  );
}
