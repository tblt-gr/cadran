import type {
  AnnualAllocation,
  AnnualFlows,
  AnnualNetWorthSeries,
  AnnualTopExpenseCategories,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { AnnualCellValue } from './AnnualCellValue';
import { monthLabel } from './annualRowLabels';
import styles from './AnnualCharts.module.css';

function useReasonText() {
  const { t } = useTranslation();
  return (reason: string | null) =>
    t('reports.annual.notCalculable') +
    (reason ? ` : ${t(`reports.annual.reasons.${reason}`, { defaultValue: reason })}` : '');
}

export function FlowsDataTable({ flows }: { flows: AnnualFlows }) {
  const { t, i18n } = useTranslation();
  const ids = ['cashIncome', 'budgetExpenses', 'budgetSurplus'] as const;

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.annual.month')}</th>
          {ids.map((id) => (
            <th key={id} scope="col">
              {t(`reports.annual.indicators.${id}`)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {flows.months.map((row) => (
          <tr key={row.month}>
            <th scope="row">{monthLabel(row.month, i18n.language)}</th>
            {ids.map((id) => (
              <td key={id}>
                <AnnualCellValue
                  assetCode={flows.assetCode}
                  kind="FLOW"
                  reason={row[id].reason}
                  value={row[id].value}
                />
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function NetWorthDataTable({ netWorth }: { netWorth: AnnualNetWorthSeries }) {
  const { t, i18n } = useTranslation();

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.annual.month')}</th>
          <th scope="col">{t('reports.annual.indicators.endNetWorth')}</th>
        </tr>
      </thead>
      <tbody>
        {netWorth.months.map((point) => (
          <tr key={point.month}>
            <th scope="row">{monthLabel(point.month, i18n.language)}</th>
            <td>
              <AnnualCellValue
                assetCode={netWorth.assetCode}
                kind="STOCK"
                reason={point.value.reason}
                value={point.value.value}
              />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function TopCategoriesDataTable({ top }: { top: AnnualTopExpenseCategories }) {
  const { t, i18n } = useTranslation();
  const reasonText = useReasonText();
  if (top.reason !== null || top.assetCode === null) return <p>{reasonText(top.reason)}</p>;
  const assetCode = top.assetCode;
  const rows = [
    ...top.items,
    ...(top.other ? [{ ...top.other, label: t('reports.annual.charts.other') }] : []),
  ];

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.annual.charts.category')}</th>
          <th scope="col">{t('reports.annual.charts.amount')}</th>
          <th scope="col">{t('reports.annual.charts.share')}</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((item, index) => (
          <tr key={item.categoryId ?? `other-${index}`}>
            <th scope="row">{item.label}</th>
            <td>{formatAmount(item.total, assetCode, i18n.language)}</td>
            <td>{formatRatioPercentage(item.share, i18n.language)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function AllocationDataTable({ allocation }: { allocation: AnnualAllocation }) {
  const { t, i18n } = useTranslation();
  const reasonText = useReasonText();
  if (allocation.reason !== null || allocation.assetCode === null) {
    return <p>{reasonText(allocation.reason)}</p>;
  }
  const assetCode = allocation.assetCode;

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.annual.charts.group')}</th>
          <th scope="col">{t('reports.annual.charts.amount')}</th>
          <th scope="col">{t('reports.annual.charts.share')}</th>
        </tr>
      </thead>
      <tbody>
        {allocation.items.map((item) => (
          <tr key={item.groupId}>
            <th scope="row">{item.label}</th>
            <td>
              {item.value === null
                ? reasonText(item.reason)
                : formatAmount(item.value, assetCode, i18n.language)}
            </td>
            <td>{item.share === null ? '' : formatRatioPercentage(item.share, i18n.language)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
