import type { NetWorthPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { Icon } from '@/components/ui/icon/Icon';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatAmount, formatCalendarMonth, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './WealthChart.module.css';

interface WealthChartProps {
  points: NetWorthPoint[];
}

function pointValueLabel(point: NetWorthPoint, locale: string): string | null {
  if (point.total === null) {
    return null;
  }

  const shown = point.total.display ?? {
    value: point.total.value,
    assetCode: point.total.assetCode,
  };

  return formatAmount(shown.value, shown.assetCode, locale);
}

/**
 * The net-worth curve and its tabular alternative.
 *
 * The drawing is decorative: it is announced as one image and every figure
 * behind it stays readable in the table, where a month without a computable
 * value states its reason instead of dropping to zero.
 */
export function WealthChart({ points }: WealthChartProps) {
  const { i18n, t } = useTranslation();
  const curvePoints: CurvePoint[] = points.map((point) => {
    const value = pointValueLabel(point, i18n.language);
    const date = formatCalendarNumericDay(point.on);

    return {
      axisLabel: date,
      geometryValue: point.total?.display?.value ?? point.total?.value ?? null,
      hitLabel: value === null ? date : `${date}, ${value}`,
      key: point.on,
      tooltipTitle: <time dateTime={point.on}>{date}</time>,
      value: <NetWorthFigure amount={point.total} reason={point.reason} />,
    };
  });
  const hasCurve = curvePoints.some((point) => point.geometryValue !== null);

  return (
    <figure className={styles.chart} aria-labelledby="wealth-chart-title">
      <figcaption id="wealth-chart-title">{t('dashboard.chart.title')}</figcaption>
      {!hasCurve ? (
        <p className={styles.emptyPlot}>{t('dashboard.chart.empty')}</p>
      ) : (
        <CurveChart
          label={t('dashboard.chart.summary', {
            endPeriod: formatCalendarMonth(points[points.length - 1].on, i18n.language),
            startPeriod: formatCalendarMonth(points[0].on, i18n.language),
          })}
          points={curvePoints}
          timeAxisLabel={t('dashboard.chart.xAxis')}
          valueAxisLabel={t('dashboard.chart.valueColumn')}
        />
      )}
      <details className={styles.details}>
        <summary>
          <span className={styles.disclosure}>
            <Icon name="chevron-right" size={14} />
          </span>
          {t('dashboard.chart.showTable')}
        </summary>
        <div className={styles.tableWrap}>
          <table aria-label={t('dashboard.chart.title')} className={styles.table}>
            <thead>
              <tr>
                <th scope="col">{t('dashboard.chart.monthColumn')}</th>
                <th scope="col">{t('dashboard.chart.valueColumn')}</th>
              </tr>
            </thead>
            <tbody>
              {points.map((point) => (
                <tr key={point.on}>
                  <th scope="row">{formatCalendarMonth(point.on, i18n.language)}</th>
                  <td>
                    <NetWorthFigure amount={point.total} reason={point.reason} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </figure>
  );
}
