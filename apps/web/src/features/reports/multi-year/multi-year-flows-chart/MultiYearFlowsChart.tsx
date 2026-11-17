import type { AnnualReport } from '@cadran/api-client';
import { Bar, ComposedChart, Line, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { useBoxSize } from '@/hooks/use-box-size';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ExactTooltip } from '@/features/reports/annual/ExactTooltip';
import {
  FLOW_BAR_SERIES,
  FLOW_CHART_STACK_OFFSET,
  FLOW_SURPLUS_SERIES,
} from '@/features/reports/annual/FlowsChart';
import {
  scaleForGeometry,
  prefersReducedMotion,
  yearStartTicks,
} from '@/features/reports/annual/chartGeometry';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import { axisTickLabel, formatMonthLabel } from '@/lib/monthLabel';
import { firstDataMonth, fromFirstDataMonth } from '@/features/reports/annual/annualColumns';
import {
  FLOW_SERIES_IDS,
  flowAssetCode,
  type MultiYearFlowsProps,
} from '@/features/reports/multi-year/multiYearChartData';
import styles from '@/features/reports/multi-year/MultiYearCharts.module.css';

export function FlowsDataTable({ annualReports, byYear, report }: MultiYearFlowsProps) {
  const { t, i18n } = useTranslation();
  const assetCode = flowAssetCode(annualReports, report);
  const months = byYear ? null : (flowEntries(annualReports) ?? null);
  const years = report.years;

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
        {(months ?? years).map((entry) => (
          <tr key={'month' in entry ? entry.month : entry.year}>
            <th scope="row">
              {'month' in entry ? monthLabel(entry.month, i18n.language) : entry.year}
            </th>
            {FLOW_SERIES_IDS.map((id) => {
              const cell = 'month' in entry ? entry[id] : entry.flows[id];
              return (
                <td key={id}>
                  {cell?.value == null ? (
                    <EmptyValue
                      label={t('reports.annual.notCalculable')}
                      reason={
                        cell?.reason
                          ? t(`reports.annual.reasons.${cell.reason}`, {
                              defaultValue: cell.reason,
                            })
                          : null
                      }
                    />
                  ) : (
                    formatAnnualValue(
                      cell.value,
                      'FLOW',
                      'month' in entry ? assetCode : entry.flows.assetCode,
                      i18n.language,
                    )
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

/** The monthly series of the detailed range; the first year starts at its first month with data. */
export function flowEntries(annualReports: AnnualReport[] | undefined) {
  return annualReports?.flatMap((annualReport, index) =>
    index === 0
      ? fromFirstDataMonth(annualReport.charts.flows.months, firstDataMonth(annualReport.rows))
      : annualReport.charts.flows.months,
  );
}

export function MultiYearFlowsChart({ annualReports, byYear, report }: MultiYearFlowsProps) {
  const { t, i18n } = useTranslation();
  const { ref, size } = useBoxSize();
  const entries = (byYear ? undefined : flowEntries(annualReports)) ?? report.years;
  const values = entries.flatMap((entry) =>
    FLOW_SERIES_IDS.map((id) => {
      const value = ('month' in entry ? entry[id].value : entry.flows[id].value) ?? null;
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
    return [
      'month' in entry ? formatMonthLabel(entry.month, i18n.language) : String(entry.year),
      ...FLOW_SERIES_IDS.map((id) => {
        const cell = 'month' in entry ? entry[id] : entry.flows[id];
        const text =
          cell?.value == null
            ? t('reports.annual.notCalculable')
            : formatAnnualValue(
                cell.value,
                'FLOW',
                'month' in entry ? assetCode : entry.flows.assetCode,
                i18n.language,
              );
        return `${t(`reports.annual.indicators.${id}`)} : ${text}`;
      }),
    ];
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
          <XAxis
            dataKey="year"
            interval={0}
            tickFormatter={(key: string) =>
              /^\d{4}-\d{2}$/.test(key) ? axisTickLabel(key, i18n.language) : key
            }
            ticks={
              entries.length > 0 && 'month' in entries[0]!
                ? yearStartTicks(
                    data.map((point) => point.year),
                    false,
                  )
                : undefined
            }
          />
          <Tooltip content={<ExactTooltip linesFor={linesFor} />} />
          {FLOW_BAR_SERIES.map((series) => (
            <Bar
              className={`flow-bar-${series.dataKey}`}
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
