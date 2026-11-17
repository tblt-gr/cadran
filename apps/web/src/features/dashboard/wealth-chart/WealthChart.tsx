import type { NetWorthPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import {
  formatAmount,
  formatCalendarDay,
  formatCalendarMonth,
  formatCalendarNumericDay,
} from '@/lib/decimal';
import styles from './WealthChart.module.css';

interface WealthChartProps {
  points: NetWorthPoint[] | null;
  /** The point under the pointer or the focus, null once it leaves: the card shows it, not a tooltip. */
  onActivePoint?: (point: NetWorthPoint | null) => void;
  /** The day a tap pinned on a screen that cannot hover, kept until a tap outside the card. */
  selectedOn?: string | null;
  onSelect?: (point: NetWorthPoint) => void;
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
export function WealthChart({
  points,
  onActivePoint,
  onSelect,
  selectedOn = null,
}: WealthChartProps) {
  const { i18n, t } = useTranslation();
  const curvePoints: CurvePoint[] = (points ?? []).map((point) => {
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
      <figcaption className="sr-only" id="wealth-chart-title">
        {t('dashboard.chart.title')}
      </figcaption>
      {points === null ? (
        <CurveChartSkeleton />
      ) : !hasCurve ? (
        <p className={styles.emptyPlot}>{t('dashboard.chart.empty')}</p>
      ) : (
        <CurveChart
          hideTooltip={onActivePoint !== undefined}
          onSelect={
            onSelect
              ? (key) => {
                  const point = points.find((candidate) => candidate.on === key);
                  if (point) onSelect(point);
                }
              : undefined
          }
          selectedKey={selectedOn}
          onActivePointChange={
            onActivePoint
              ? (key) => onActivePoint(points.find((point) => point.on === key) ?? null)
              : undefined
          }
          label={t('dashboard.chart.summary', {
            endPeriod: formatCalendarMonth(points[points.length - 1].on, i18n.language),
            startPeriod: formatCalendarMonth(points[0].on, i18n.language),
          })}
          points={curvePoints}
          timeAxisLabel={t('dashboard.chart.xAxis')}
          valueAxisLabel={t('dashboard.chart.valueColumn')}
        />
      )}
      {/* The block clips the table: a `display: table` box ignores overflow, so a long history would
          stretch the page far below its content. */}
      <div className="sr-only">
        <table>
          <caption>{t('dashboard.chart.title')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('dashboard.chart.dateColumn')}</th>
              <th scope="col">{t('dashboard.chart.valueColumn')}</th>
            </tr>
          </thead>
          <tbody>
            {points === null ? (
              <tr>
                <th scope="row">
                  <SkeletonBlock />
                </th>
                <td>
                  <SkeletonBlock />
                </td>
              </tr>
            ) : (
              points.map((point) => (
                <tr key={point.on}>
                  <th scope="row">{formatCalendarDay(point.on, i18n.language)}</th>
                  <td>
                    <NetWorthFigure amount={point.total} reason={point.reason} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </figure>
  );
}
