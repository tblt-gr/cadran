import type { NetWorthAllocationEntry, NetWorthAmount, NetWorthReason } from '@cadran/api-client';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { allocationEntries } from '@/features/accounts/grouping-mode/allocationEntries';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Treemap } from 'recharts';
import { DonutChart, type DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { Icon } from '@/components/ui/icon/Icon';
import { useBoxSize } from '@/hooks/use-box-size';
import { formatAmount } from '@/lib/decimal';
import { sliceFromEntry, type AllocationSlice } from './allocationSlice';
import { sliceCaption } from './sliceCaption';
import { TreemapTile } from './treemap-tile/TreemapTile';
import styles from './AllocationCharts.module.css';

type ChartView = 'pie' | 'treemap';

interface AllocationChartsProps {
  allocation: NetWorthAllocationEntry[];
  className?: string;
  mode?: GroupingMode;
  modeToggle?: ReactNode;
  onViewChange?: (view: ChartView) => void;
  reason: NetWorthReason | null;
  total?: NetWorthAmount | null;
}

function amountLabel(amount: NetWorthAmount | null, fallback: string, locale: string): string {
  if (amount === null || amount.display === null) {
    return fallback;
  }

  return formatAmount(amount.display.value, amount.display.assetCode, locale);
}

/**
 * Exclusive top-level weights as Treemap or pie. Slice geometry uses a bounded
 * dimensionless ratio; every visible figure is a backend display string.
 */
export function AllocationCharts({
  allocation,
  className,
  mode = 'PARENT',
  modeToggle = null,
  onViewChange,
  reason: _reason,
  total = null,
}: AllocationChartsProps) {
  const { i18n, t } = useTranslation();
  const [view, setView] = useState<ChartView>('treemap');
  const { ref, size } = useBoxSize();
  const entries = allocationEntries(allocation, mode, (amount) => amount.value);
  const slices = entries
    .map(sliceFromEntry)
    .filter((slice): slice is AllocationSlice => slice !== null);

  function changeView(next: ChartView) {
    setView(next);
    onViewChange?.(next);
  }

  if (!allocation.some((entry) => entry.depth === 1)) {
    return null;
  }

  const ready = size.width > 0 && size.height > 0;
  const fallback = t('states.notCalculable.label');
  const donutSlices: DonutSlice[] = slices.map((slice) => ({
    amountText: amountLabel(slice.value, fallback, i18n.language),
    caption: sliceCaption(
      slice.parentLabel === null ? slice.label : `${slice.label} (${slice.parentLabel})`,
      slice.percentDisplay,
    ),
    fill: slice.fill,
    key: slice.groupId,
    size: slice.size,
  }));

  return (
    <div
      className={className ? `${styles.wrap} ${className}` : styles.wrap}
      data-allocation-view={view}
    >
      <div className={styles.toggles}>
        {modeToggle}
        <div
          className={styles.viewToggles}
          role="group"
          aria-label={t('dashboard.allocation.chartView')}
        >
          <button
            aria-label={t('dashboard.allocation.treemap')}
            aria-pressed={view === 'treemap'}
            className="icon-ghost"
            onClick={() => changeView('treemap')}
            type="button"
          >
            <Icon name="treemap" size={16} />
          </button>
          <button
            aria-label={t('dashboard.allocation.pie')}
            aria-pressed={view === 'pie'}
            className="icon-ghost"
            onClick={() => changeView('pie')}
            type="button"
          >
            <Icon name="pie" size={16} />
          </button>
        </div>
      </div>
      {slices.length === 0 ? null : (
        <div className={styles.plot} data-visual-mask ref={ref}>
          {!ready ? null : view === 'treemap' ? (
            <Treemap
              content={TreemapTile}
              data={slices}
              dataKey="size"
              fill="var(--color-transparent)"
              height={size.height}
              isAnimationActive={false}
              isUpdateAnimationActive={false}
              nameKey="name"
              width={size.width}
            />
          ) : (
            <DonutChart
              height={size.height}
              label={t('dashboard.allocation.title')}
              slices={donutSlices}
              totalText={amountLabel(total, fallback, i18n.language)}
              width={size.width}
            />
          )}
        </div>
      )}
    </div>
  );
}

export type { ChartView };
