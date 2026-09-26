import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import {
  AllocationDataTable,
  FlowsDataTable,
  NetWorthDataTable,
  TopCategoriesDataTable,
} from './ChartDataTables';
import { ChartFrame } from './ChartFrame';
import { DonutChart } from './DonutChart';
import { FlowsChart, NetWorthChart } from './FlowsChart';
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
        <NetWorthChart netWorth={netWorth} />
      </ChartFrame>
      <ChartFrame
        data={<TopCategoriesDataTable top={top} />}
        title={t('reports.annual.charts.topCategories')}
      >
        {top.reason !== null ? (
          <p>{reasonText(top.reason)}</p>
        ) : (
          <DonutChart
            slices={[
              ...top.items.map((item) => ({
                key: item.categoryId ?? item.label ?? '',
                label: item.label ?? '',
                share: item.share,
              })),
              ...(top.other
                ? [
                    {
                      key: 'other',
                      label: t('reports.annual.charts.other'),
                      share: top.other.share,
                    },
                  ]
                : []),
            ]}
          />
        )}
      </ChartFrame>
      <ChartFrame
        data={<AllocationDataTable allocation={allocation} />}
        title={t('reports.annual.charts.allocation')}
      >
        {allocation.reason !== null ? (
          <p>{reasonText(allocation.reason)}</p>
        ) : (
          <DonutChart
            slices={allocation.items
              .filter((item) => item.value !== null)
              .map((item) => ({ key: item.groupId, label: item.label, share: item.share }))}
          />
        )}
      </ChartFrame>
    </div>
  );
}
