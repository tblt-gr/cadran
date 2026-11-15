import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { usePersistedToggle } from '@/hooks/use-persisted-toggle';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import {
  columnLookup,
  FLOW_SERIES_IDS,
  NET_WORTH_ID,
  type MultiYearChartsProps,
} from './multiYearChartData';
import {
  flowEntries,
  FlowsDataTable,
  MultiYearFlowsChart,
} from './multi-year-flows-chart/MultiYearFlowsChart';
import {
  MultiYearNetWorthChart,
  NetWorthDataTable,
} from './multi-year-net-worth-chart/MultiYearNetWorthChart';
import styles from './MultiYearCharts.module.css';

/**
 * Grouped bars for income, expenses and surplus per year, and an end-of-year net worth
 * line. Flow figures are supplied independently of the selected report columns.
 */
export function MultiYearCharts({ annualReports, report }: MultiYearChartsProps) {
  const { t } = useTranslation();
  const [byYear, setByYear] = usePersistedToggle('cadran.report.multiYear.flowsByYear');
  const entries = (byYear ? undefined : flowEntries(annualReports)) ?? report.years;
  const flowCells = entries.flatMap((entry) =>
    FLOW_SERIES_IDS.map((id) => ('month' in entry ? entry[id] : entry.flows[id])),
  );
  const hasFlows =
    flowCells.some((cell) => cell.value !== null) &&
    (annualReports
      ? annualReports.some((annualReport) => annualReport.charts.flows.assetCode !== null)
      : report.years.some((year) => year.flows.assetCode !== null));
  const flowReason = flowCells.find(
    (cell) => cell.reason === 'MIXED_ASSETS' || cell.reason === 'MIXED_METRIC_POLICIES',
  )?.reason;
  const hasNetWorth = annualReports
    ? annualReports.some((annualReport) => annualReport.charts.netWorth.assetCode !== null)
    : columnLookup(report, NET_WORTH_ID) !== null;

  return (
    <div className={styles.grid}>
      <ChartFrame
        actions={
          <button
            aria-label={t('reports.multiYear.charts.flowsByYear')}
            aria-pressed={byYear}
            className="icon-ghost"
            onClick={() => setByYear(!byYear)}
            title={t('reports.multiYear.charts.flowsByYear')}
            type="button"
          >
            <Icon name="calendar" size={16} />
          </button>
        }
        data={<FlowsDataTable annualReports={annualReports} byYear={byYear} report={report} />}
        title={t('reports.multiYear.charts.flows')}
      >
        {hasFlows ? (
          <MultiYearFlowsChart annualReports={annualReports} byYear={byYear} report={report} />
        ) : (
          <p>
            {flowReason
              ? `${t('reports.annual.notCalculable')} : ${t(`reports.annual.reasons.${flowReason}`, { defaultValue: flowReason })}`
              : t('reports.multiYear.charts.noFlows')}
          </p>
        )}
      </ChartFrame>
      <ChartFrame
        data={
          hasNetWorth ? (
            <NetWorthDataTable annualReports={annualReports} report={report} />
          ) : undefined
        }
        title={t('reports.multiYear.charts.netWorth')}
      >
        {hasNetWorth ? (
          <MultiYearNetWorthChart annualReports={annualReports} report={report} />
        ) : (
          <p>{t('reports.multiYear.charts.unavailable')}</p>
        )}
      </ChartFrame>
    </div>
  );
}
