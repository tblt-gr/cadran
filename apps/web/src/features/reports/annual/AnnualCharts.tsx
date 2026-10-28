import type { AnnualCategoryShare, AnnualReport } from '@cadran/api-client';
import { Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { allocationEntries } from '@/features/accounts/grouping-mode/allocationEntries';
import { useGroupingMode } from '@/features/accounts/grouping-mode/useGroupingMode';
import { canonicalGroupColor } from '@/features/accounts/group-color';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { lazyComponent } from '@/lib/lazyComponent';
import { firstDataMonth, fromFirstDataMonth } from './annualColumns';
import { FlowsDataTable, NetWorthDataTable } from './ChartDataTables';
import { ChartFrame } from './ChartFrame';
import { AnnualDonut, type AnnualDonutItem, type AnnualLegendRow } from './AnnualDonut';
import { AnnualNetWorthChart } from './AnnualNetWorthChart';
import styles from './AnnualCharts.module.css';

// recharts loads on demand, after the report's own figures.
const FlowsChart = lazyComponent(() => import('./FlowsChart'), 'FlowsChart');

const TOP_VISIBLE_ROWS = 6;

function toRow(item: AnnualCategoryShare): AnnualDonutItem & AnnualLegendRow {
  return {
    amount: item.total,
    color: canonicalCategoryColor(item.color) ?? undefined,
    key: item.categoryId ?? item.label ?? '',
    label: item.label ?? '',
    share: item.share,
  };
}

/** The four datasets come from the backend and do not depend on the selected columns. */
export function AnnualCharts({
  includeTrends = true,
  report,
}: {
  includeTrends?: boolean;
  report: AnnualReport;
}) {
  const { t } = useTranslation();
  const [mode, setMode] = useGroupingMode('cadran.reports.allocation.grouping', 'PRIMARY');
  const { topExpenseCategories: top, allocation } = report.charts;
  // The series start at the first month with data, not in January of a year that began later.
  const first = firstDataMonth(report.rows);
  const flows = {
    ...report.charts.flows,
    months: fromFirstDataMonth(report.charts.flows.months, first),
  };
  const netWorth = {
    ...report.charts.netWorth,
    months:
      report.charts.netWorth.opening === null
        ? fromFirstDataMonth(report.charts.netWorth.months, first)
        : report.charts.netWorth.months,
  };
  const reasonText = (reason: string | null) =>
    t('reports.annual.notCalculable') +
    (reason ? ` : ${t(`reports.annual.reasons.${reason}`, { defaultValue: reason })}` : '');
  /** Every category is its own slice: the top items followed by the "other"
   * bucket's individual items, exact amount and share each, no summed
   * "Autres" slice. */
  const topCategoryRows = [...top.items, ...(top.other?.items ?? [])].map(toRow);

  const itemsById = new Map(allocation.items.map((item) => [item.groupId, item]));
  const allocationRows = allocationEntries(allocation.items, mode, (amount) => amount).map(
    (entry) => ({
      amount: entry.value,
      color: canonicalGroupColor(entry.color) ?? undefined,
      key: entry.groupId,
      label: entry.path,
      reason:
        (mode === 'PARENT'
          ? itemsById.get(entry.groupId)?.reason
          : itemsById.get(entry.groupId)?.directReason) ?? null,
      share: entry.share,
    }),
  );

  return (
    <div className={styles.grid}>
      {includeTrends ? (
        <>
          <ChartFrame
            data={<FlowsDataTable flows={flows} />}
            title={t('reports.annual.charts.flows')}
          >
            <Suspense fallback={<SkeletonChartArea />}>
              <FlowsChart flows={flows} />
            </Suspense>
          </ChartFrame>
          <ChartFrame
            data={<NetWorthDataTable netWorth={netWorth} />}
            title={t('reports.annual.charts.netWorth')}
          >
            <AnnualNetWorthChart netWorth={netWorth} />
          </ChartFrame>
        </>
      ) : null}
      <ChartFrame title={t('reports.annual.charts.topCategories')}>
        {top.reason !== null || top.assetCode === null ? (
          <p>{reasonText(top.reason)}</p>
        ) : (
          <AnnualDonut
            assetCode={top.assetCode}
            label={t('reports.annual.charts.topCategories')}
            legend={topCategoryRows}
            slices={topCategoryRows}
            visibleRows={TOP_VISIBLE_ROWS}
          />
        )}
      </ChartFrame>
      <ChartFrame
        actions={<GroupingModeToggle mode={mode} onChange={setMode} />}
        title={t('reports.annual.charts.allocation')}
      >
        {allocation.reason !== null || allocation.assetCode === null ? (
          <p>{reasonText(allocation.reason)}</p>
        ) : (
          <AnnualDonut
            assetCode={allocation.assetCode}
            label={t('reports.annual.charts.allocation')}
            legend={allocationRows}
            slices={allocationRows.flatMap(({ amount, color, key, label, share }) =>
              amount === null || share === null ? [] : [{ amount, color, key, label, share }],
            )}
          />
        )}
      </ChartFrame>
    </div>
  );
}
