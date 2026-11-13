import type { AnnualReport, GoalPeriodEvaluation } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { AnnualCellValue } from './AnnualCellValue';
import { annualCellHref } from './annualColumns';
import { ColumnHeader } from './ColumnHeader';
import { isEmptyState, monthLabel } from './annualRowLabels';
import styles from './AnnualMonthCards.module.css';

interface AnnualMonthCardProps {
  columns: AnnualReport['columns'];
  months: readonly GoalPeriodEvaluation[] | undefined;
  row: AnnualReport['rows'][number];
}

/**
 * One month of the mobile report. A month with figures is closed on its first column, whose
 * value comes from the server as is; a future or empty month has nothing to open.
 */
export function AnnualMonthCard({ columns, months, row }: AnnualMonthCardProps) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const title = <h3>{monthLabel(row.month, i18n.language)}</h3>;
  const className = `card ${styles.card} ${isEmptyState(row.state) ? styles.dimmed : ''}`;

  if (row.state === 'FUTURE' || row.state === 'NO_DATA') {
    return (
      <li className={className}>
        {title}
        <p>
          <EmptyValue
            quiet
            label={t(row.state === 'FUTURE' ? 'reports.annual.future' : 'reports.annual.noData')}
          />
        </p>
      </li>
    );
  }

  const lead = columns[0];
  const leadCell = lead ? row.cells[lead.id] : undefined;
  return (
    <li className={`${className} ${styles.collapsible}`}>
      <Disclosure
        meta={
          lead ? (
            <AnnualCellValue
              assetCode={lead.assetCode}
              kind={lead.kind}
              reason={leadCell?.reason ?? null}
              value={leadCell?.value ?? null}
            />
          ) : null
        }
        onToggle={setOpen}
        open={open}
        spread
        title={title}
      >
        <dl>
          {columns.map((column) => {
            const marks = marksFor(months, row.month, column.id);
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
