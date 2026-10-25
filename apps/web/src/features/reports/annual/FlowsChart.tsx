import type { AnnualFlows } from '@cadran/api-client';
import { Bar, ComposedChart, Line, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import styles from './AnnualCharts.module.css';
import { useBoxSize } from '@/hooks/use-box-size';
import { ExactTooltip } from './ExactTooltip';
import { flowChartData, prefersReducedMotion, yearStartTicks } from './chartGeometry';
import { axisTickLabel } from '@/lib/monthLabel';
import { flowTooltipLines } from './flowTooltipLines';

export const FLOW_CHART_COLORS = {
  cashIncome: 'var(--positive)',
  budgetExpenses: 'var(--negative)',
  budgetSurplus: 'var(--chart-allocation-1)',
} as const;

export const FLOW_BAR_SERIES = [
  { dataKey: 'cashIncome', fill: FLOW_CHART_COLORS.cashIncome, stackId: 'flows' },
  { dataKey: 'budgetExpenses', fill: FLOW_CHART_COLORS.budgetExpenses, stackId: 'flows' },
] as const;

/** Recharts' positive/negative stack keeps one time bucket on either side of zero. */
export const FLOW_CHART_STACK_OFFSET = 'sign' as const;

export const FLOW_SURPLUS_SERIES = {
  dataKey: 'budgetSurplus',
  stroke: FLOW_CHART_COLORS.budgetSurplus,
} as const;

export function FlowsChart({ flows }: { flows: AnnualFlows }) {
  const { t, i18n } = useTranslation();
  const { ref, size } = useBoxSize();
  const data = flowChartData(flows.months);
  const axisLabel = (key: string) => {
    const row = flows.months.find((m) => m.month.slice(5) === key);
    return row ? axisTickLabel(row.month, i18n.language) : key;
  };
  const linesFor = (month: string) => {
    const row = flows.months.find((m) => m.month.slice(5) === month);
    if (!row) return [];
    return flowTooltipLines(row, flows.assetCode, i18n.language, t);
  };

  return (
    <div ref={ref} className={styles.flows}>
      {size.width > 0 ? (
        <ComposedChart
          accessibilityLayer
          data={data}
          height={220}
          role="img"
          stackOffset={FLOW_CHART_STACK_OFFSET}
          title={t('reports.annual.charts.flows')}
          width={size.width}
        >
          <XAxis
            dataKey="month"
            interval={0}
            tickFormatter={axisLabel}
            ticks={yearStartTicks(data.map((point) => point.month))}
          />
          <Tooltip content={<ExactTooltip linesFor={linesFor} />} />
          {FLOW_BAR_SERIES.map((series) => (
            <Bar
              dataKey={series.dataKey}
              fill={series.fill}
              isAnimationActive={!prefersReducedMotion()}
              key={series.dataKey}
              stackId={series.stackId}
            />
          ))}
          <Line
            dataKey={FLOW_SURPLUS_SERIES.dataKey}
            dot={false}
            isAnimationActive={!prefersReducedMotion()}
            stroke={FLOW_SURPLUS_SERIES.stroke}
            type="monotone"
          />
        </ComposedChart>
      ) : null}
    </div>
  );
}
