import type { NetWorthAmount, NetWorthAllocationEntry, NetWorthReason } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Treemap } from 'recharts';
import { DonutChart, type DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { Icon } from '@/components/ui/icon/Icon';
import { useBoxSize } from '@/hooks/use-box-size';
import { formatAmount } from '@/lib/decimal';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { allocationSize } from './allocationSize';
import { sliceCaption } from './sliceCaption';
import styles from './AllocationCharts.module.css';

const SLICE_COLORS = [
  'var(--chart-allocation-1)',
  'var(--chart-allocation-2)',
  'var(--chart-allocation-3)',
  'var(--chart-allocation-4)',
] as const;

type ChartView = 'pie' | 'treemap';

interface AllocationSlice {
  [key: string]: unknown;
  fill: string;
  groupId: string;
  label: string;
  name: string;
  percentDisplay: string | null;
  size: number;
  value: NetWorthAmount | null;
}

interface AllocationChartsProps {
  allocation: NetWorthAllocationEntry[];
  className?: string;
  onViewChange?: (view: ChartView) => void;
  reason: NetWorthReason | null;
  total?: NetWorthAmount | null;
}

function sliceFromEntry(entry: NetWorthAllocationEntry, index: number): AllocationSlice | null {
  if (entry.depth !== 1 || entry.share.percent === null) {
    return null;
  }

  const size = allocationSize(entry.share.percent);
  if (size === null) {
    return null;
  }

  return {
    fill: SLICE_COLORS[index % SLICE_COLORS.length],
    groupId: entry.groupId,
    label: entry.label,
    name: entry.label,
    percentDisplay: entry.share.percentDisplay,
    size,
    value: entry.value,
  };
}

function isSlice(value: unknown): value is AllocationSlice {
  return (
    typeof value === 'object' &&
    value !== null &&
    'percentDisplay' in value &&
    'label' in value &&
    'value' in value
  );
}

function amountLabel(amount: NetWorthAmount | null, fallback: string, locale: string): string {
  if (amount === null || amount.display === null) {
    return fallback;
  }

  return formatAmount(amount.display.value, amount.display.assetCode, locale);
}

function TreemapTile(node: {
  depth: number;
  height: number;
  index: number;
  name?: string;
  payload?: unknown;
  width: number;
  x: number;
  y: number;
}) {
  if (node.depth !== 1 || node.width <= 0 || node.height <= 0) {
    return (
      <rect
        fill="var(--color-transparent)"
        height={Math.max(0, node.height)}
        stroke="var(--color-transparent)"
        width={Math.max(0, node.width)}
        x={node.x}
        y={node.y}
      />
    );
  }

  const slice = isSlice(node.payload) ? node.payload : isSlice(node) ? node : null;
  const percent =
    slice !== null && slice.percentDisplay !== null
      ? formatSharePercent(slice.percentDisplay, { fractionDigits: 2 })
      : null;

  return (
    <g>
      <rect
        className={styles.tile}
        fill={SLICE_COLORS[node.index % SLICE_COLORS.length]}
        height={node.height}
        width={node.width}
        x={node.x}
        y={node.y}
      />
      {node.width > 48 && node.height > 24 ? (
        <text className={styles.tileLabel} x={node.x + 8} y={node.y + 16}>
          {node.name}
          {percent ? ` · ${percent}` : ''}
        </text>
      ) : null}
    </g>
  );
}

/**
 * Exclusive top-level weights as Treemap or pie. Slice geometry uses a bounded
 * dimensionless ratio; every visible figure is a backend display string.
 */
export function AllocationCharts({
  allocation,
  className,
  onViewChange,
  reason: _reason,
  total = null,
}: AllocationChartsProps) {
  const { i18n, t } = useTranslation();
  const [view, setView] = useState<ChartView>('treemap');
  const { ref, size } = useBoxSize();
  const roots = allocation.filter((entry) => entry.depth === 1);
  const slices = roots
    .map((entry, index) => sliceFromEntry(entry, index))
    .filter((slice): slice is AllocationSlice => slice !== null);

  function changeView(next: ChartView) {
    setView(next);
    onViewChange?.(next);
  }

  if (roots.length === 0) {
    return null;
  }

  const ready = size.width > 0 && size.height > 0;
  const fallback = t('states.notCalculable.label');
  const donutSlices: DonutSlice[] = slices.map((slice) => ({
    amountText: amountLabel(slice.value, fallback, i18n.language),
    caption: sliceCaption(slice.label, slice.percentDisplay),
    fill: slice.fill,
    key: slice.groupId,
    size: slice.size,
  }));

  return (
    <div
      className={className ? `${styles.wrap} ${className}` : styles.wrap}
      data-allocation-view={view}
    >
      <div className={styles.toggles} role="group" aria-label={t('dashboard.allocation.chartView')}>
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
      {slices.length === 0 ? null : (
        <div className={styles.plot} ref={ref}>
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
