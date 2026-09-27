import type { AnnualCell, AnnualFlows } from '@cadran/api-client';
import { Bar, ComposedChart, Line, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import styles from './AnnualCharts.module.css';
import { useBoxSize } from '@/hooks/use-box-size';
import { formatAnnualValue } from './annualFormat';
import { ExactTooltip } from './ExactTooltip';
import { FLOW_SERIES_IDS, flowChartData, prefersReducedMotion } from './chartGeometry';

export const FLOW_CHART_COLORS = {
  cashIncome: 'var(--positive)',
  budgetExpenses: 'var(--negative)',
  budgetSurplus: 'var(--chart-allocation-1)',
} as const;

export const FLOW_BAR_SERIES = [
  { dataKey: 'cashIncome', fill: FLOW_CHART_COLORS.cashIncome, stackId: 'flows' },
  { dataKey: 'budgetExpenses', fill: FLOW_CHART_COLORS.budgetExpenses, stackId: 'flows' },
] as const;

export const FLOW_SURPLUS_SERIES = {
  dataKey: 'budgetSurplus', stroke: FLOW_CHART_COLORS.budgetSurplus,
} as const;

function exact(cell: AnnualCell, assetCode: string | null, locale: string, reasonText: string) {
  return cell.value === null
    ? reasonText
    : formatAnnualValue(cell.value, 'FLOW', assetCode, locale);
}

export function FlowsChart({ flows }: { flows: AnnualFlows }) {
  const { t, i18n } = useTranslation();
  const { ref, size } = useBoxSize();
  const data = flowChartData(flows.months);
  const linesFor = (month: string) => {
    const row = flows.months.find((m) => m.month.slice(5) === month);
    if (!row) return [];
    return FLOW_SERIES_IDS.map(
      (id) =>
        `${t(`reports.annual.indicators.${id}`)} : ${exact(row[id], flows.assetCode, i18n.language, t('reports.annual.notCalculable'))}`,
    );
  };

  return (
    <div ref={ref} className={styles.flows}>
      {size.width > 0 ? (
        <ComposedChart
          accessibilityLayer
          data={data}
          height={220}
          role="img"
          title={t('reports.annual.charts.flows')}
          width={size.width}
        >
          <XAxis dataKey="month" />
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
