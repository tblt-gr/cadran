import type { AnnualReport, GoalEvaluation, GoalPeriodEvaluation } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { AnnualCellValue } from './AnnualCellValue';
import { annualCellHref } from './annualColumns';
import { flowSummaryOf } from './flow-summary/flowSummaryOf';
import { ColumnHeader } from './ColumnHeader';
import { isEmptyState, monthLabel } from './annualRowLabels';
import styles from './AnnualMonthCards.module.css';

interface AnnualMonthCardProps {
  columns: AnnualReport['columns'];
  /** Replaces the month name, e.g. to carry the year in a multi-year chronology. */
  label?: string;
  months?: readonly GoalPeriodEvaluation[] | undefined;
  /** Overrides the goal marks of a figure when they do not come from `months`. */
  marksOf?: (columnId: string) => readonly GoalEvaluation[];
  row: AnnualReport['rows'][number];
}

function noop() {}

/**
 * One month of the mobile report. A month with figures is closed on its income and expenses, whose
 * values come from the server as is; a future or empty month has nothing to open.
 */
export function AnnualMonthCard({ columns, label, marksOf, months, row }: AnnualMonthCardProps) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const name = label ?? monthLabel(row.month, i18n.language);
  const className = `card ${styles.card} ${isEmptyState(row.state) ? styles.dimmed : ''}`;

  if (row.state === 'FUTURE' || row.state === 'NO_DATA') {
    const reason = t(row.state === 'FUTURE' ? 'reports.annual.future' : 'reports.annual.noData');
    return (
      <li className={`${className} ${styles.collapsible}`}>
        {/* Same line as a folded month, with nothing to open: a hyphen for each figure. */}
        <Disclosure
          disabled
          meta={flowSummaryOf(columns, () => (
            <EmptyValue label={reason} quiet />
          ))}
          onToggle={noop}
          open={false}
          spread
          title={<h3>{name}</h3>}
        >
          {null}
        </Disclosure>
      </li>
    );
  }

  return (
    <li className={`${className} ${styles.collapsible}`}>
      <Disclosure
        meta={flowSummaryOf(columns, (column) => {
          const cell = row.cells[column.id];
          return (
            <AnnualCellValue
              assetCode={column.assetCode}
              kind={column.kind}
              reason={cell?.reason ?? null}
              value={cell?.value ?? null}
            />
          );
        })}
        onToggle={setOpen}
        open={open}
        spread
        title={<h3>{name}</h3>}
      >
        <dl>
          {columns.map((column) => {
            const marks = marksOf ? marksOf(column.id) : marksFor(months, row.month, column.id);
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
      </Disclosure>
    </li>
  );
}
