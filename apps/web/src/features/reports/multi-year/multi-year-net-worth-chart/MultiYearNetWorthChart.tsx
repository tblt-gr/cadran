import type { AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { monthLabel, monthYearLabel } from '@/features/reports/annual/annualRowLabels';
import { firstDataMonth, fromFirstDataMonth } from '@/features/reports/annual/annualColumns';
import { netWorthPoints } from '@/features/reports/annual/netWorthPoints';
import {
  columnLookup,
  NET_WORTH_ID,
  type MultiYearChartsProps,
} from '@/features/reports/multi-year/multiYearChartData';
import styles from '@/features/reports/multi-year/MultiYearCharts.module.css';

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

interface NetWorthRow {
  isOpening: boolean;
  /** `YYYY-MM` for a month, null when the row is a whole year. */
  month: string | null;
  value: string | null;
  year: number | null;
}

function netWorthRows(
  annualReports: AnnualReport[] | undefined,
  report: MultiYearReport,
): NetWorthRow[] {
  if (!annualReports) {
    return report.years.map((entry) => ({
      isOpening: false,
      month: null,
      value: entry.cells[NET_WORTH_ID]?.value ?? null,
      year: entry.year,
    }));
  }

  return annualReports
    .flatMap(({ charts, rows }, index) => {
      const { netWorth } = charts;
      // Only the first year draws its opening: later openings repeat the previous December.
      if (index > 0) {
        return netWorthPoints({ months: netWorth.months, opening: null });
      }
      const months =
        netWorth.opening === null
          ? fromFirstDataMonth(netWorth.months, firstDataMonth(rows))
          : netWorth.months;

      return netWorthPoints({ months, opening: netWorth.opening });
    })
    .map(({ isOpening, point }) => ({
      isOpening,
      month: point.month,
      value: point.value.value,
      year: null,
    }));
}

export function NetWorthDataTable({ annualReports, report }: MultiYearChartsProps) {
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
        {netWorthRows(annualReports, report).map((row) => (
          <tr key={row.month ?? row.year}>
            <th scope="row">
              {row.month === null
                ? row.year
                : row.isOpening
                  ? monthYearLabel(row.month, i18n.language)
                  : monthLabel(row.month, i18n.language)}
            </th>
            <td>
              {row.value === null ? (
                <EmptyValue quiet label={t('reports.annual.notCalculable')} />
              ) : (
                formatAnnualValue(row.value, 'STOCK', assetCode, i18n.language)
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function MultiYearNetWorthChart({ annualReports, report }: MultiYearChartsProps) {
  const { t, i18n } = useTranslation();
  const assetCode = netWorthAssetCode(annualReports, report);
  const points: CurvePoint[] = netWorthRows(annualReports, report).map((row) => {
    const label = row.month ?? String(row.year);
    const text =
      row.value === null ? null : formatAnnualValue(row.value, 'STOCK', assetCode, i18n.language);

    return {
      axisLabel: label,
      geometryValue: row.value,
      hitLabel: text === null ? label : `${label}, ${text}`,
      key: label,
      tooltipTitle: label,
      value: text === null ? <EmptyValue quiet label={t('reports.annual.notCalculable')} /> : text,
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
