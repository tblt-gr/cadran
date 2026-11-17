import type { AnnualColumn, MultiYearReport } from '@cadran/api-client';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { flowSummaryOf } from '@/features/reports/annual/flow-summary/flowSummaryOf';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import { MultiYearCellValue } from './MultiYearCellValue';
import { multiYearYearNotes } from './multiYearYearNotes';
import { YearNotes } from './YearNotes';

interface DetailedYearCardProps {
  columns: AnnualColumn[];
  label: number;
  open: boolean;
  year: MultiYearReport['years'][number] | undefined;
  onToggle: (year: number) => void;
}

/**
 * One year of the mobile chronology. Folded, it keeps its income and expenses in view; unfolded, it
 * lists every selected column and lets the months below it show. Changes against the previous year
 * are left to the desktop table: they do not fit a narrow card.
 */
export function DetailedYearCard({ columns, label, onToggle, open, year }: DetailedYearCardProps) {
  const cellOf = (column: AnnualColumn) => {
    const cell = year?.cells[column.id];
    return cell ? (
      <MultiYearCellValue
        assetCode={column.assetCode}
        cell={cell}
        kind={column.kind}
        showChange={false}
      />
    ) : null;
  };

  return (
    <li className={`card ${cardStyles.card} ${cardStyles.collapsible}`}>
      <Disclosure
        meta={year ? flowSummaryOf(columns, cellOf) : null}
        onToggle={(next) => {
          if (next !== open) onToggle(label);
        }}
        open={open}
        spread
        title={<h3>{label}</h3>}
      >
        {year && multiYearYearNotes(year).length > 0 ? (
          <p>
            <YearNotes year={year} />
          </p>
        ) : null}
        {year ? (
          <dl>
            {columns.map((column) =>
              year.cells[column.id] ? (
                <div key={column.id}>
                  <dt>
                    <ColumnHeader column={column} />
                  </dt>
                  <dd>{cellOf(column)}</dd>
                </div>
              ) : null,
            )}
          </dl>
        ) : null}
      </Disclosure>
    </li>
  );
}
