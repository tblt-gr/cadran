import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import {
  AllocationDataTable,
  FlowsDataTable,
  NetWorthDataTable,
  TopCategoriesDataTable,
} from './ChartDataTables';
import { ChartFrame } from './ChartFrame';
import { AnnualDonut } from './AnnualDonut';
import { AnnualNetWorthChart } from './AnnualNetWorthChart';
import { FlowsChart } from './FlowsChart';
import styles from './AnnualCharts.module.css';

/** The four datasets come from the backend and do not depend on the selected columns. */
export function AnnualCharts({ report }: { report: AnnualReport }) {
  const { t } = useTranslation();
  const { flows, netWorth, topExpenseCategories: top, allocation } = report.charts;
  const reasonText = (reason: string | null) =>
    t('reports.annual.notCalculable') +
    (reason ? ` : ${t(`reports.annual.reasons.${reason}`, { defaultValue: reason })}` : '');

  return (
    <div className={styles.grid}>
      <ChartFrame data={<FlowsDataTable flows={flows} />} title={t('reports.annual.charts.flows')}>
        <FlowsChart flows={flows} />
      </ChartFrame>
      <ChartFrame
        data={<NetWorthDataTable netWorth={netWorth} />}
        title={t('reports.annual.charts.netWorth')}
      >
        <AnnualNetWorthChart netWorth={netWorth} />
      </ChartFrame>
      <ChartFrame
        data={<TopCategoriesDataTable top={top} />}
        title={t('reports.annual.charts.topCategories')}
      >
        {top.reason !== null || top.assetCode === null ? (
          <p>{reasonText(top.reason)}</p>
        ) : (
          <AnnualDonut
            assetCode={top.assetCode}
            items={[
              ...top.items.map((item) => ({
                amount: item.total,
                key: item.categoryId ?? item.label ?? '',
                label: item.label ?? '',
                share: item.share,
              })),
              ...(top.other
                ? [
                    {
                      amount: top.other.total,
                      key: 'other',
                      label: t('reports.annual.charts.other'),
                      share: top.other.share,
                    },
                  ]
                : []),
            ]}
            label={t('reports.annual.charts.topCategories')}
          />
        )}
      </ChartFrame>
      <ChartFrame
        data={<AllocationDataTable allocation={allocation} />}
        title={t('reports.annual.charts.allocation')}
      >
        {allocation.reason !== null || allocation.assetCode === null ? (
          <p>{reasonText(allocation.reason)}</p>
        ) : (
          <AnnualDonut
            assetCode={allocation.assetCode}
            items={allocation.items.flatMap((item) =>
              item.value === null || item.share === null
                ? []
                : [{ amount: item.value, key: item.groupId, label: item.label, share: item.share }],
            )}
            label={t('reports.annual.charts.allocation')}
          />
        )}
      </ChartFrame>
    </div>
  );
}
