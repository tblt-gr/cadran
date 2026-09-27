import type { MultiYearReport } from '@cadran/api-client';
import { Bar, BarChart, Tooltip, XAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { useBoxSize } from '@/hooks/use-box-size';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import { ExactTooltip } from '@/features/reports/annual/ExactTooltip';
import { scaleForGeometry, prefersReducedMotion } from '@/features/reports/annual/chartGeometry';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import styles from './MultiYearCharts.module.css';

const FLOW_SERIES_IDS = ['cashIncome', 'budgetExpenses', 'budgetSurplus'] as const;
const NET_WORTH_ID = 'endNetWorth';
const COLORS = {
  cashIncome: 'var(--positive)',
  budgetExpenses: 'var(--negative)',
  budgetSurplus: 'var(--gold-300)',
} as const;

interface MultiYearChartsProps {
  report: MultiYearReport;
}

function columnLookup(report: MultiYearReport, id: string) {
  return report.columns.find((column) => column.id === id) ?? null;
}

function FlowsDataTable({ report }: { report: MultiYearReport }) {
  const { t, i18n } = useTranslation();
  const assetCode = columnLookup(report, 'cashIncome')?.assetCode ?? null;

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.multiYear.year')}</th>
          {FLOW_SERIES_IDS.map((id) => (
            <th key={id} scope="col">
              {t(`reports.annual.indicators.${id}`)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {report.years.map((year) => (
          <tr key={year.year}>
            <th scope="row">{year.year}</th>
            {FLOW_SERIES_IDS.map((id) => {
              const cell = year.cells[id];
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

function MultiYearFlowsChart({ report }: { report: MultiYearReport }) {
  const { t, i18n } = useTranslation();
  const { ref, size } = useBoxSize();
  const values = report.years.flatMap((year) =>
    FLOW_SERIES_IDS.map((id) => year.cells[id]?.value ?? null),
  );
  const scaled = scaleForGeometry(values);
  const data = report.years.map((year, index) => ({
    year: String(year.year),
    ...Object.fromEntries(
      FLOW_SERIES_IDS.map((id, s) => [id, scaled[index * FLOW_SERIES_IDS.length + s]]),
    ),
  }));
  const assetCode = columnLookup(report, 'cashIncome')?.assetCode ?? null;
  const linesFor = (label: string) => {
    const year = report.years.find((y) => String(y.year) === label);
    if (!year) return [];
    return FLOW_SERIES_IDS.map((id) => {
      const cell = year.cells[id];
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
        <BarChart
          accessibilityLayer
          data={data}
          height={220}
          role="img"
          title={t('reports.multiYear.charts.flows')}
          width={size.width}
        >
          <XAxis dataKey="year" />
          <Tooltip content={<ExactTooltip linesFor={linesFor} />} />
          {FLOW_SERIES_IDS.map((id) => (
            <Bar
              dataKey={id}
              fill={COLORS[id]}
              isAnimationActive={!prefersReducedMotion()}
              key={id}
            />
          ))}
        </BarChart>
      ) : null}
    </div>
  );
}

function NetWorthDataTable({ report }: { report: MultiYearReport }) {
  const { t, i18n } = useTranslation();
  const assetCode = columnLookup(report, NET_WORTH_ID)?.assetCode ?? null;

  return (
    <table className={styles.data}>
      <thead>
        <tr>
          <th scope="col">{t('reports.multiYear.year')}</th>
          <th scope="col">{t('reports.annual.indicators.endNetWorth')}</th>
        </tr>
      </thead>
      <tbody>
        {report.years.map((year) => {
          const cell = year.cells[NET_WORTH_ID];
          return (
            <tr key={year.year}>
              <th scope="row">{year.year}</th>
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

function MultiYearNetWorthChart({ report }: { report: MultiYearReport }) {
  const { t, i18n } = useTranslation();
  const assetCode = columnLookup(report, NET_WORTH_ID)?.assetCode ?? null;
  const points: CurvePoint[] = report.years.map((year) => {
    const cell = year.cells[NET_WORTH_ID];
    const text =
      cell?.value == null ? null : formatAnnualValue(cell.value, 'STOCK', assetCode, i18n.language);

    return {
      axisLabel: String(year.year),
      geometryValue: cell?.value ?? null,
      hitLabel: text === null ? String(year.year) : `${year.year}, ${text}`,
      key: String(year.year),
      tooltipTitle: String(year.year),
      value: text === null ? <EmptyValue label={t('reports.annual.notCalculable')} /> : text,
    };
  });

  return (
    <div className={styles.netWorth}>
      <CurveChart
        label={t('reports.multiYear.charts.netWorth')}
        points={points}
        timeAxisLabel={t('reports.multiYear.year')}
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
export function MultiYearCharts({ report }: MultiYearChartsProps) {
  const { t } = useTranslation();
  const hasFlows = FLOW_SERIES_IDS.every((id) => columnLookup(report, id) !== null);
  const hasNetWorth = columnLookup(report, NET_WORTH_ID) !== null;

  return (
    <div className={styles.grid}>
      <ChartFrame
        data={hasFlows ? <FlowsDataTable report={report} /> : undefined}
        title={t('reports.multiYear.charts.flows')}
      >
        {hasFlows ? (
          <MultiYearFlowsChart report={report} />
        ) : (
          <p>{t('reports.multiYear.charts.unavailable')}</p>
        )}
      </ChartFrame>
      <ChartFrame
        data={hasNetWorth ? <NetWorthDataTable report={report} /> : undefined}
        title={t('reports.multiYear.charts.netWorth')}
      >
        {hasNetWorth ? (
          <MultiYearNetWorthChart report={report} />
        ) : (
          <p>{t('reports.multiYear.charts.unavailable')}</p>
        )}
      </ChartFrame>
    </div>
  );
}
