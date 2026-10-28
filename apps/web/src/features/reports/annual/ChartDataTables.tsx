import type { AnnualFlows, AnnualNetWorthSeries } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AnnualCellValue } from './AnnualCellValue';
import { monthLabel, monthYearLabel } from './annualRowLabels';
import { netWorthPoints } from './netWorthPoints';
import styles from './AnnualCharts.module.css';

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
        {netWorthPoints(netWorth).map(({ isOpening, point }) => (
          <tr key={point.month}>
            <th scope="row">
              {isOpening
                ? monthYearLabel(point.month, i18n.language)
                : monthLabel(point.month, i18n.language)}
            </th>
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
