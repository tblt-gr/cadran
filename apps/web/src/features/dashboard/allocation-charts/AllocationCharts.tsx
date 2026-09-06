import type {
  NetWorthAllocationEntry,
  NetWorthAmount,
  NetWorthReason,
  NetWorthShare,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Cell,
  Pie,
  PieChart,
  Tooltip,
  Treemap,
  type PieLabelRenderProps,
  type TreemapNode,
} from 'recharts';
import { Icon } from '@/components/ui/icon/Icon';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';
import styles from './AllocationCharts.module.css';

const CHART_WIDTH = 360;
const CHART_HEIGHT = 240;
/** Recharts writes width/height on the wrapper style; drop them so the
 *  allocation bar-width assertion still sees only the list fill. */
const CHART_BOX = { height: undefined, width: undefined };
const SLICE_COLORS = [
  'var(--chart-allocation-1)',
  'var(--chart-allocation-2)',
  'var(--chart-allocation-3)',
  'var(--chart-allocation-4)',
] as const;

type ChartView = 'pie' | 'treemap';

interface AllocationSlice {
  [key: string]: unknown;
  groupId: string;
  label: string;
  name: string;
  share: NetWorthShare;
  size: number;
  value: NetWorthAmount | null;
}

interface AllocationChartsProps {
  allocation: NetWorthAllocationEntry[];
  className?: string;
  reason: NetWorthReason | null;
}

function sliceFromEntry(entry: NetWorthAllocationEntry): AllocationSlice | null {
  if (entry.depth !== 1 || entry.share.percent === null) {
    return null;
  }

  const size = Number(entry.share.percent);
  if (!Number.isFinite(size)) {
    return null;
  }

  return {
    groupId: entry.groupId,
    label: entry.label,
    name: entry.label,
    share: entry.share,
    size,
    value: entry.value,
  };
}

function isSlice(value: unknown): value is AllocationSlice {
  return (
    typeof value === 'object' &&
    value !== null &&
    'share' in value &&
    'label' in value &&
    'value' in value
  );
}

function shareLabel(share: NetWorthShare, notCalculable: string): string {
  return share.percentDisplay === null
    ? notCalculable
    : formatSharePercent(share.percentDisplay, { fractionDigits: 2 });
}

function AllocationTooltip({
  active,
  payload,
  reason,
}: {
  active?: boolean;
  payload?: ReadonlyArray<{ payload?: unknown }>;
  reason: NetWorthReason | null;
}) {
  const { t } = useTranslation();
  const slice = payload?.[0]?.payload;
  if (!active || !isSlice(slice)) {
    return null;
  }

  return (
    <div className={styles.tooltip}>
      <strong>{slice.label}</strong>
      <span>{shareLabel(slice.share, t('states.notCalculable.label'))}</span>
      <NetWorthFigure amount={slice.value} reason={reason} />
    </div>
  );
}

function pieLabel(props: PieLabelRenderProps) {
  const slice = isSlice(props.payload) ? props.payload : null;
  if (slice === null || slice.share.percentDisplay === null || props.x == null || props.y == null) {
    return null;
  }

  return (
    <text
      className={styles.label}
      dominantBaseline="central"
      textAnchor={props.textAnchor}
      x={props.x}
      y={props.y}
    >
      {formatSharePercent(slice.share.percentDisplay, { fractionDigits: 2 })}
    </text>
  );
}

function TreemapTile(node: TreemapNode) {
  if (node.depth !== 1 || node.width <= 0 || node.height <= 0) {
    return <g />;
  }

  const slice = isSlice(node) ? node : null;
  const percent =
    slice !== null && slice.share.percentDisplay !== null
      ? formatSharePercent(slice.share.percentDisplay, { fractionDigits: 2 })
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
      {node.width > 56 && node.height > 28 ? (
        <text className={styles.label} x={node.x + 8} y={node.y + 16}>
          {node.name}
          {percent ? ` · ${percent}` : ''}
        </text>
      ) : null}
    </g>
  );
}

/**
 * Exclusive top-level weights as Treemap or pie. Slice geometry reads
 * `Number(share.percent)`; every visible figure is a backend display string.
 */
export function AllocationCharts({ allocation, className, reason }: AllocationChartsProps) {
  const { t } = useTranslation();
  const [view, setView] = useState<ChartView>('treemap');
  const roots = allocation.filter((entry) => entry.depth === 1);
  const slices = roots
    .map(sliceFromEntry)
    .filter((slice): slice is AllocationSlice => slice !== null);

  if (roots.length === 0) {
    return null;
  }

  const tooltip = (
    <Tooltip
      animationDuration={0}
      content={<AllocationTooltip reason={reason} />}
      contentStyle={{
        background: 'var(--color-transparent)',
        border: 'var(--border-none)',
        padding: 0,
      }}
      isAnimationActive={false}
    />
  );

  return (
    <div className={className ? `${styles.wrap} ${className}` : styles.wrap}>
      <div className={styles.toggles} role="group" aria-label={t('dashboard.allocation.chartView')}>
        <button
          aria-pressed={view === 'treemap'}
          className={styles.toggle}
          onClick={() => setView('treemap')}
          type="button"
        >
          <Icon name="treemap" size={16} />
          {t('dashboard.allocation.treemap')}
        </button>
        <button
          aria-pressed={view === 'pie'}
          className={styles.toggle}
          onClick={() => setView('pie')}
          type="button"
        >
          <Icon name="pie" size={16} />
          {t('dashboard.allocation.pie')}
        </button>
      </div>
      {slices.length === 0 ? null : view === 'treemap' ? (
        <Treemap
          content={TreemapTile}
          data={slices}
          dataKey="size"
          height={CHART_HEIGHT}
          isAnimationActive={false}
          isUpdateAnimationActive={false}
          nameKey="name"
          style={CHART_BOX}
          width={CHART_WIDTH}
        >
          {tooltip}
        </Treemap>
      ) : (
        <PieChart height={CHART_HEIGHT} style={CHART_BOX} width={CHART_WIDTH}>
          <Pie
            cx={CHART_WIDTH / 2}
            cy={CHART_HEIGHT / 2}
            data={slices}
            dataKey="size"
            isAnimationActive={false}
            label={pieLabel}
            nameKey="name"
            outerRadius={90}
          >
            {slices.map((slice, index) => (
              <Cell fill={SLICE_COLORS[index % SLICE_COLORS.length]} key={slice.groupId} />
            ))}
          </Pie>
          {tooltip}
        </PieChart>
      )}
    </div>
  );
}
