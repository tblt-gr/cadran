import { Cell, Pie, PieChart } from 'recharts';
import { useTranslation } from 'react-i18next';
import { decimalRatioForGeometry } from '@/lib/decimal';
import { prefersReducedMotion } from './chartGeometry';

const COLORS = [
  'var(--chart-allocation-1)',
  'var(--chart-allocation-2)',
  'var(--chart-allocation-3)',
  'var(--chart-allocation-4)',
  'var(--gold-300)',
  'var(--info)',
  'var(--positive)',
];

export interface DonutSlice {
  key: string;
  label: string;
  /** Exact decimal share, drawn only. */
  share: string | null;
}

const SIZE = 200;

export function DonutChart({ slices }: { slices: DonutSlice[] }) {
  const { t } = useTranslation();
  const data = slices
    .filter((slice): slice is DonutSlice & { share: string } => slice.share !== null)
    .map((slice) => ({ ...slice, size: decimalRatioForGeometry(slice.share, '1') }));

  return (
    <PieChart
      accessibilityLayer
      height={SIZE}
      role="img"
      title={t('reports.annual.charts.donut')}
      width={SIZE}
    >
      <Pie
        cx={SIZE / 2}
        cy={SIZE / 2}
        data={data}
        dataKey="size"
        innerRadius={SIZE * 0.3}
        isAnimationActive={!prefersReducedMotion()}
        nameKey="label"
        outerRadius={SIZE * 0.48}
      >
        {data.map((slice, index) => (
          <Cell fill={COLORS[index % COLORS.length]} key={slice.key} strokeWidth={0} />
        ))}
      </Pie>
    </PieChart>
  );
}
