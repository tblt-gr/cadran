import type { AnnualCell, AnnualFlowMonth } from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import { formatMonthLabel } from '@/lib/monthLabel';
import { formatAnnualValue } from './annualFormat';
import { FLOW_SERIES_IDS } from './chartGeometry';

function exact(cell: AnnualCell, assetCode: string | null, locale: string, reasonText: string) {
  return cell.value === null
    ? reasonText
    : formatAnnualValue(cell.value, 'FLOW', assetCode, locale);
}

/** Month first, then income, expenses and balance, each as the backend's exact figure. */
export function flowTooltipLines(
  row: AnnualFlowMonth,
  assetCode: string | null,
  locale: string,
  t: (key: ParseKeys) => string,
): string[] {
  return [
    formatMonthLabel(row.month, locale),
    ...FLOW_SERIES_IDS.map(
      (id) =>
        `${t(`reports.annual.indicators.${id}`)} : ${exact(row[id], assetCode, locale, t('reports.annual.notCalculable'))}`,
    ),
  ];
}
