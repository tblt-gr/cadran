import { useState } from 'react';
import { Cell, Pie, PieChart } from 'recharts';
import styles from './DonutChart.module.css';

const DONUT_COLORS = [
  'var(--chart-allocation-1)',
  'var(--chart-allocation-2)',
  'var(--chart-allocation-3)',
  'var(--chart-allocation-4)',
] as const;

export interface DonutSlice {
  key: string;
  /** Overrides the palette colour picked by position. */
  fill?: string;
  /** Text shown under the amount while the slice is hovered, e.g. "Loyer · 42,00 %". */
  caption: string;
  /** Formatted exact amount shown in the centre while the slice is hovered. */
  amountText: string | null;
  /** Bounded, dimensionless geometry weight; never a displayed figure. */
  size: number;
}

interface DonutChartProps {
  height: number;
  /** Centre text when no slice is hovered; nothing when null. */
  totalText: string | null;
  /** Text announced for the drawing. */
  label: string;
  slices: DonutSlice[];
  width: number;
}

/** Donut whose centre shows the hovered slice's amount and caption. Motion is always off. */
export function DonutChart({ height, label, slices, totalText, width }: DonutChartProps) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const pieWidth = Math.max(width, 1);
  const pieHeight = Math.max(height, 1);
  const radius = Math.max(48, Math.min(pieWidth, pieHeight) / 2 - 16);
  const focused = activeIndex === null ? null : (slices[activeIndex] ?? null);
  const centre = focused === null ? totalText : focused.amountText;

  return (
    <PieChart
      aria-label={label}
      className={styles.donut}
      height={pieHeight}
      role="img"
      width={pieWidth}
    >
      <Pie
        cx={pieWidth / 2}
        cy={pieHeight / 2}
        data={slices}
        dataKey="size"
        innerRadius={radius * 0.62}
        isAnimationActive={false}
        nameKey="caption"
        onMouseEnter={(_, index) => setActiveIndex(index)}
        onMouseLeave={() => setActiveIndex(null)}
        outerRadius={radius}
        paddingAngle={0}
      >
        {slices.map((slice, index) => (
          <Cell
            fill={slice.fill ?? DONUT_COLORS[index % DONUT_COLORS.length]}
            fillOpacity={activeIndex === index ? 0.72 : 1}
            key={slice.key}
            stroke="var(--color-transparent)"
            strokeWidth={0}
          />
        ))}
      </Pie>
      {centre === null ? null : (
        <text
          className={styles.centerTotal}
          textAnchor="middle"
          x={pieWidth / 2}
          y={pieHeight / 2 - (focused === null ? 0 : 8)}
        >
          {centre}
        </text>
      )}
      {focused === null ? null : (
        <text
          className={styles.centerTitle}
          textAnchor="middle"
          x={pieWidth / 2}
          y={pieHeight / 2 + 16}
        >
          {focused.caption}
        </text>
      )}
    </PieChart>
  );
}
