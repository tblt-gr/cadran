import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { Bar, ComposedChart, Line, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { useBoxSize } from '@/hooks/use-box-size';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import { ExactTooltip } from '@/features/reports/annual/ExactTooltip';
import {
  FLOW_BAR_SERIES,
  FLOW_CHART_STACK_OFFSET,
  FLOW_SURPLUS_SERIES,
} from '@/features/reports/annual/FlowsChart';
import { scaleForGeometry, prefersReducedMotion } from '@/features/reports/annual/chartGeometry';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import styles from './MultiYearCharts.module.css';

const FLOW_SERIES_IDS = ['cashIncome', 'budgetExpenses', 'budgetSurplus'] as const;
const NET_WORTH_ID = 'endNetWorth';
interface MultiYearChartsProps {
  annualReports?: AnnualReport[];
  report: MultiYearReport;
}

function columnLookup(report: MultiYearReport, id: string) {
  return report.columns.find((column) => column.id === id) ?? null;
}

function flowAssetCode(annualReports: AnnualReport[] | undefined, report: MultiYearReport) {
  return (
    annualReports?.find((annualReport) => annualReport.charts.flows.assetCode !== null)?.charts
      .flows.assetCode ??
    columnLookup(report, 'cashIncome')?.assetCode ??
    null
  );
}

export function netWorthAssetCode(
  annualReports: AnnualReport[] | undefined,
  report: MultiYearReport,
) {
  return (
    annualReports?.find((annualReport) => annualReport.charts.netWorth.assetCode !== null)?.charts
      .netWorth.assetCode ??
    columnLookup(report, NET_WORTH_ID)?.assetCode ??
    null
  );
}

function FlowsDataTable({ annualReports, report }: MultiYearChartsProps) {
  const { t, i18n } = useTranslation();
  const assetCode = flowAssetCode(annualReports, report);
  const months = annualReports?.flatMap((annualReport) => annualReport.charts.flows.months) ?? null;

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{months ? t('reports.annual.month') : t('reports.multiYear.year')}</th>
          {FLOW_SERIES_IDS.map((id) => (
            <th key={id} scope="col">
              {t(`reports.annual.indicators.${id}`)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {(months ?? report.years).map((entry) => (
          <tr key={'month' in entry ? entry.month : entry.year}>
            <th scope="row">
              {'month' in entry ? monthLabel(entry.month, i18n.language) : entry.year}
            </th>
            {FLOW_SERIES_IDS.map((id) => {
              const cell = 'month' in entry ? entry[id] : entry.cells[id];
              return (
                <td key={id}>
                  {cell?.value == null ? (
                    <EmptyValue label={t('reports.annual.notCalculable')} />
                  ) : (
                    formatAnnualValue(cell.value, 'FLOW', assetCode, i18n.language)
                  )}
                </td>
              );
            })}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function MultiYearFlowsChart({ annualReports, report }: MultiYearChartsProps) {
  const { t, i18n } = useTranslation();
  const { ref, size } = useBoxSize();
  const entries =
    annualReports?.flatMap((annualReport) => annualReport.charts.flows.months) ?? report.years;
  const values = entries.flatMap((entry) =>
    FLOW_SERIES_IDS.map((id) => {
      const value = ('month' in entry ? entry[id].value : entry.cells[id]?.value) ?? null;
      return id === 'budgetExpenses' && value !== null && !value.startsWith('-')
        ? `-${value}`
        : value;
    }),
  );
  const scaled = scaleForGeometry(values);
  const data = entries.map((entry, index) => ({
    year: 'month' in entry ? entry.month : String(entry.year),
    ...Object.fromEntries(
      FLOW_SERIES_IDS.map((id, s) => [id, scaled[index * FLOW_SERIES_IDS.length + s]]),
    ),
  }));
  const assetCode = flowAssetCode(annualReports, report);
  const linesFor = (label: string) => {
    const entry = entries.find(
      (item) => ('month' in item ? item.month : String(item.year)) === label,
    );
    if (!entry) return [];
    return FLOW_SERIES_IDS.map((id) => {
      const cell = 'month' in entry ? entry[id] : entry.cells[id];
      const text =
        cell?.value == null
          ? t('reports.annual.notCalculable')
          : formatAnnualValue(cell.value, 'FLOW', assetCode, i18n.language);
      return `${t(`reports.annual.indicators.${id}`)} : ${text}`;
    });
  };

  return (
    <div className={styles.flows} ref={ref}>
      {size.width > 0 ? (
        <ComposedChart
          accessibilityLayer
          data={data}
          height={220}
          role="img"
          stackOffset={FLOW_CHART_STACK_OFFSET}
          title={t('reports.multiYear.charts.flows')}
          width={size.width}
        >
          <XAxis dataKey="year" />
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

function NetWorthDataTable({ annualReports, report }: MultiYearChartsProps) {
  const { t, i18n } = useTranslation();
  const assetCode = netWorthAssetCode(annualReports, report);

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">
            {annualReports ? t('reports.annual.month') : t('reports.multiYear.year')}
          </th>
          <th scope="col">{t('reports.annual.indicators.endNetWorth')}</th>
        </tr>
      </thead>
      <tbody>
        {(
          annualReports?.flatMap((annualReport) => annualReport.charts.netWorth.months) ??
          report.years
        ).map((entry) => {
          const cell = 'month' in entry ? entry.value : entry.cells[NET_WORTH_ID];
          return (
            <tr key={'month' in entry ? entry.month : entry.year}>
              <th scope="row">
                {'month' in entry ? monthLabel(entry.month, i18n.language) : entry.year}
              </th>
              <td>
                {cell?.value == null ? (
                  <EmptyValue label={t('reports.annual.notCalculable')} />
                ) : (
                  formatAnnualValue(cell.value, 'STOCK', assetCode, i18n.language)
                )}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

function MultiYearNetWorthChart({ annualReports, report }: MultiYearChartsProps) {
  const { t, i18n } = useTranslation();
  const assetCode = netWorthAssetCode(annualReports, report);
  const entries =
    annualReports?.flatMap((annualReport) => annualReport.charts.netWorth.months) ?? report.years;
  const points: CurvePoint[] = entries.map((entry) => {
    const cell = 'month' in entry ? entry.value : entry.cells[NET_WORTH_ID];
    const label = 'month' in entry ? entry.month : String(entry.year);
    const text =
      cell?.value == null ? null : formatAnnualValue(cell.value, 'STOCK', assetCode, i18n.language);

    return {
      axisLabel: label,
      geometryValue: cell?.value ?? null,
      hitLabel: text === null ? label : `${label}, ${text}`,
      key: label,
      tooltipTitle: label,
      value: text === null ? <EmptyValue label={t('reports.annual.notCalculable')} /> : text,
    };
  });

  return (
    <div className={styles.netWorth}>
      <CurveChart
        label={t('reports.multiYear.charts.netWorth')}
        points={points}
        timeAxisLabel={annualReports ? t('reports.annual.month') : t('reports.multiYear.year')}
        valueAxisLabel={t('reports.annual.indicators.endNetWorth')}
      />
    </div>
  );
}

/**
 * Grouped bars for income, expenses and surplus per year, and an end-of-year net worth
 * line, built from the selected report columns. A chart is skipped, with an explanatory
 * note, when its columns are not part of the caller's selected preferences.
 */
export function MultiYearCharts({ annualReports, report }: MultiYearChartsProps) {
  const { t } = useTranslation();
  const hasFlows = annualReports
    ? annualReports.some((annualReport) => annualReport.charts.flows.assetCode !== null)
    : FLOW_SERIES_IDS.every((id) => columnLookup(report, id) !== null);
  const hasNetWorth = annualReports
    ? annualReports.some((annualReport) => annualReport.charts.netWorth.assetCode !== null)
    : columnLookup(report, NET_WORTH_ID) !== null;

  return (
    <div className={styles.grid}>
      <ChartFrame
        data={
          hasFlows ? <FlowsDataTable annualReports={annualReports} report={report} /> : undefined
        }
        title={t('reports.multiYear.charts.flows')}
      >
        {hasFlows ? (
          <MultiYearFlowsChart annualReports={annualReports} report={report} />
        ) : (
          <p>{t('reports.multiYear.charts.unavailable')}</p>
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
