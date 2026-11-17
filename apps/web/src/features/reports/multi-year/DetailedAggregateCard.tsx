import type { AnnualColumn, MultiYearReport } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { flowSummaryOf } from '@/features/reports/annual/flow-summary/flowSummaryOf';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import { DetailedAggregateFigure } from './DetailedAggregateFigure';
import {
  multiYearDetailedAggregateFigure,
  type MultiYearDetailedAggregateRowId,
} from './multiYearDetailedAggregate';

interface DetailedAggregateCardProps {
  columns: AnnualColumn[];
  report: MultiYearReport;
  rowId: MultiYearDetailedAggregateRowId;
  onExplain: (columnId: string) => void;
}

/** One statistic (total, average...) of the whole detailed period, folded on its income and expenses. */
export function DetailedAggregateCard({
  columns,
  onExplain,
  report,
  rowId,
}: DetailedAggregateCardProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  return (
    <li className={`card ${cardStyles.card} ${cardStyles.collapsible}`}>
      <Disclosure
        meta={flowSummaryOf(columns, (column) => {
          const aggregate = report.detailedPeriod[column.id];
          if (!aggregate) return null;
          const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
          return (
            <AnnualCellValue
              assetCode={column.assetCode}
              kind={column.kind}
              reason={figure.reason}
              value={figure.value}
            />
          );
        })}
        onToggle={setOpen}
        open={open}
        spread
        title={<h3>{t(`reports.annual.summary.${rowId}`)}</h3>}
      >
        <dl>
          {columns.map((column) => {
            const aggregate = report.detailedPeriod[column.id];
            if (!aggregate) return null;
            return (
              <div key={column.id}>
                <dt>
                  <ColumnHeader column={column} />
                </dt>
                <dd>
                  <DetailedAggregateFigure
                    column={column}
                    figure={multiYearDetailedAggregateFigure(aggregate, rowId)}
                    onExplain={onExplain}
                    rowId={rowId}
                  />
                </dd>
              </div>
            );
          })}
        </dl>
      </Disclosure>
    </li>
  );
}
