import { useTranslation } from 'react-i18next';
import { AXIS_IDS, INDICATOR_COLUMN_IDS } from './annualColumns';

/**
 * The single source of a report column's title, used by the column picker, the table headers, the
 * cards, the explanation titles and the selected-columns list alike. Indicators and analytic axes
 * are named by the interface translations; categories, groups and accounts by the workspace's own
 * label (the backend report column and the picker catalogue carry the same one).
 */
export function useAnnualColumnLabel(): (column: { id: string; label: string }) => string {
  const { t } = useTranslation();

  return (column) => {
    const [type, reference = ''] = column.id.split(':', 2);
    if (INDICATOR_COLUMN_IDS.some((id) => id === column.id)) {
      return t(`reports.annual.indicators.${column.id as (typeof INDICATOR_COLUMN_IDS)[number]}`);
    }
    if (type === 'axis' && AXIS_IDS.some((id) => id === reference)) {
      return t(`categories.axes.${reference as (typeof AXIS_IDS)[number]}`);
    }
    return column.label;
  };
}
