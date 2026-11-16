import type { AnnualColumn } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import { useAnnualColumnLabel } from '@/features/reports/annual/columnLabel';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import type {
  MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS,
  multiYearDetailedAggregateFigure,
} from './multiYearDetailedAggregate';

interface DetailedAggregateFigureProps {
  column: AnnualColumn;
  figure: ReturnType<typeof multiYearDetailedAggregateFigure>;
  rowId: (typeof MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS)[number];
  onExplain: (columnId: string) => void;
}

/** One summary figure of the whole period; the total can be explained, an extremum names its month. */
export function DetailedAggregateFigure({
  column,
  figure,
  rowId,
  onExplain,
}: DetailedAggregateFigureProps) {
  const { t, i18n } = useTranslation();
  const labelOf = useAnnualColumnLabel();

  return (
    <>
      <AnnualCellValue
        assetCode={column.assetCode}
        kind={column.kind}
        reason={figure.reason}
        value={figure.value}
      />
      {rowId === 'total' && figure.value !== null ? (
        <InfoButton
          aria-haspopup="dialog"
          className={tableStyles.explain}
          label={t('reports.annual.explainColumn', { column: labelOf(column) })}
          onClick={() => onExplain(column.id)}
        />
      ) : null}
      {figure.month ? (
        <small>
          {' '}
          ({monthLabel(figure.month, i18n.language)} {figure.month.slice(0, 4)})
        </small>
      ) : null}
    </>
  );
}
