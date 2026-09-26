import type { AnnualCell, AnnualFlows } from '@cadran/api-client';
import { Bar, BarChart, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { formatAnnualValue } from './annualFormat';
import { ExactTooltip } from './ExactTooltip';
import { FLOW_SERIES_IDS, flowChartData, prefersReducedMotion } from './chartGeometry';

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
