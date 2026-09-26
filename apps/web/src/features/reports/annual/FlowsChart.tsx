import type { AnnualCell } from '@cadran/api-client';
import { Bar, BarChart, Line, LineChart, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { formatAnnualValue } from './annualFormat';
import { ExactTooltip } from './ExactTooltip';
import {
  FLOW_SERIES_IDS,
  flowChartData,
  prefersReducedMotion,
  scaleForGeometry,
} from './chartGeometry';
import type { AnnualFlows, AnnualNetWorthSeries } from '@cadran/api-client';

const COLORS = {
  cashIncome: 'var(--positive)',
  budgetExpenses: 'var(--negative)',
  budgetSurplus: 'var(--gold-300)',
} as const;

function exact(cell: AnnualCell, assetCode: string | null, locale: string, reasonText: string) {
  return cell.value === null
    ? reasonText
    : formatAnnualValue(cell.value, 'FLOW', assetCode, locale);
}

export function FlowsChart({ flows }: { flows: AnnualFlows }) {
  const { t, i18n } = useTranslation();
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
    <BarChart
      accessibilityLayer
      data={data}
      height={220}
      role="img"
      title={t('reports.annual.charts.flows')}
      width={520}
    >
      <XAxis dataKey="month" />
      <Tooltip content={<ExactTooltip linesFor={linesFor} />} />
      {FLOW_SERIES_IDS.map((id) => (
        <Bar dataKey={id} fill={COLORS[id]} isAnimationActive={!prefersReducedMotion()} key={id} />
      ))}
    </BarChart>
  );
}

export function NetWorthChart({ netWorth }: { netWorth: AnnualNetWorthSeries }) {
  const { t, i18n } = useTranslation();
  const scaled = scaleForGeometry(netWorth.months.map((point) => point.value.value));
  const data = netWorth.months.map((point, index) => ({
    month: point.month.slice(5),
    endNetWorth: scaled[index],
  }));
  const linesFor = (month: string) => {
    const point = netWorth.months.find((m) => m.month.slice(5) === month);
    return point
      ? [
          `${t('reports.annual.indicators.endNetWorth')} : ${exact(point.value, netWorth.assetCode, i18n.language, t('reports.annual.notCalculable'))}`,
        ]
      : [];
  };

  return (
    <LineChart
      accessibilityLayer
      data={data}
      height={220}
      role="img"
      title={t('reports.annual.charts.netWorth')}
      width={520}
    >
      <XAxis dataKey="month" />
      <Tooltip content={<ExactTooltip linesFor={linesFor} />} />
      <Line
        dataKey="endNetWorth"
        dot
        isAnimationActive={!prefersReducedMotion()}
        stroke="var(--gold-300)"
      />
    </LineChart>
  );
}
