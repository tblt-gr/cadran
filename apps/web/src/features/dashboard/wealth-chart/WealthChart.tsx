import type { NetWorthPoint } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatAmount, formatCalendarMonth, formatCalendarNumericDay } from '@/lib/decimal';
import { plotRuns, smoothPath, type PlotArea, type PlotPoint } from './plotGeometry';
import styles from './WealthChart.module.css';

const AREA: PlotArea = { height: 170, padding: 16, width: 620 };

interface WealthChartProps {
  points: NetWorthPoint[];
}

function plotX(index: number, count: number): number {
  return count > 1 ? (index * AREA.width) / (count - 1) : AREA.width / 2;
}

function tickIndices(count: number): number[] {
  if (count <= 3) {
    return Array.from({ length: count }, (_, index) => index);
  }

  return [0, Math.round((count - 1) / 2), count - 1];
}

function nearestByX(
  placed: readonly PlotPoint[],
  clientX: number,
  width: number,
  left: number,
): number | null {
  if (placed.length === 0 || width === 0) {
    return null;
  }

  const x = ((clientX - left) / width) * AREA.width;
  let best = placed[0];
  let bestDistance = Math.abs(best.x - x);
  for (const point of placed) {
    const distance = Math.abs(point.x - x);
    if (distance < bestDistance) {
      best = point;
      bestDistance = distance;
    }
  }

  return best.index;
}

function tooltipEdge(
  x: number,
  y: number,
): { edge: 'start' | 'mid' | 'end'; side: 'above' | 'below' } {
  return {
    edge: x / AREA.width < 0.18 ? 'start' : x / AREA.width > 0.82 ? 'end' : 'mid',
    side: y / AREA.height < 0.28 ? 'below' : 'above',
  };
}

function yExtent(placed: readonly PlotPoint[]): PlotPoint[] {
  if (placed.length === 0) {
    return [];
  }

  let lowest = placed[0];
  let highest = placed[0];
  for (const point of placed) {
    if (point.y > lowest.y) {
      lowest = point;
    }
    if (point.y < highest.y) {
      highest = point;
    }
  }

  return lowest.index === highest.index ? [highest] : [highest, lowest];
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
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const runs = plotRuns(
    points.map((point) => point.total?.display?.value ?? point.total?.value ?? null),
    AREA,
  );
  const placed = runs.flat();
  const yTicks = yExtent(placed);
  const active =
    activeIndex === null ? null : (placed.find((point) => point.index === activeIndex) ?? null);
  const activePoint = active === null ? undefined : points[active.index];
  const hover =
    active === null || activePoint === undefined
      ? null
      : { point: activePoint, plot: active, placement: tooltipEdge(active.x, active.y) };

  return (
    <figure className={styles.chart} aria-labelledby="wealth-chart-title">
      <figcaption id="wealth-chart-title">{t('dashboard.chart.title')}</figcaption>
      {runs.length === 0 ? (
        <p className={styles.emptyPlot}>{t('dashboard.chart.empty')}</p>
      ) : (
        <div className={styles.frame}>
          <div className={styles.yAxis} role="group" aria-label={t('dashboard.chart.valueColumn')}>
            <span aria-hidden="true" className={styles.ySizer}>
              {yTicks.map((tick) => (
                <NetWorthFigure
                  amount={points[tick.index].total}
                  key={`y-size-${tick.index}`}
                  reason={points[tick.index].reason}
                />
              ))}
            </span>
            {yTicks.map((tick) => (
              <span
                className={styles.yTick}
                key={`y-${tick.index}`}
                style={{ top: `${(tick.y / AREA.height) * 100}%` }}
              >
                <NetWorthFigure
                  amount={points[tick.index].total}
                  reason={points[tick.index].reason}
                />
              </span>
            ))}
          </div>
          <div
            className={styles.plotBody}
            onMouseLeave={() => setActiveIndex(null)}
            onMouseMove={(event) => {
              setActiveIndex(
                nearestByX(
                  placed,
                  event.clientX,
                  event.currentTarget.clientWidth,
                  event.currentTarget.getBoundingClientRect().left,
                ),
              );
            }}
          >
            <svg
              aria-label={t('dashboard.chart.summary', {
                endPeriod: formatCalendarMonth(points[points.length - 1].on, i18n.language),
                startPeriod: formatCalendarMonth(points[0].on, i18n.language),
              })}
              className={styles.plot}
              preserveAspectRatio="none"
              role="img"
              viewBox={`0 0 ${AREA.width} ${AREA.height}`}
            >
              <defs>
                <linearGradient id="wealth-chart-area" x1="0" x2="0" y1="0" y2="1">
                  <stop className={styles.areaTop} offset="0" />
                  <stop className={styles.areaBottom} offset="1" />
                </linearGradient>
              </defs>
              <g className={styles.grid}>
                <path d={`M0 30H${AREA.width}M0 90H${AREA.width}`} />
              </g>
              {runs.map((run) => {
                const line = smoothPath(run);
                const first = run[0];
                const last = run[run.length - 1];

                return (
                  <path
                    className={styles.area}
                    d={`${line} L${last.x},${AREA.height} L${first.x},${AREA.height} Z`}
                    key={`area-${first.index}`}
                  />
                );
              })}
              {runs.map((run) => (
                <path className={styles.line} d={smoothPath(run)} key={`line-${run[0].index}`} />
              ))}
              {/* A month standing alone between two uncomputable ones has no
                  segment to belong to. Without a mark it would read as no data at
                  all, which is the opposite of what it is. */}
              {runs
                .filter((run) => run.length === 1)
                .map((run) => (
                  <circle
                    className={styles.point}
                    cx={run[0].x}
                    cy={run[0].y}
                    key={`point-${run[0].index}`}
                    r="4"
                  />
                ))}
            </svg>
            {placed.map((point) => {
              const datum = points[point.index];
              const value = pointValueLabel(datum, i18n.language);
              const date = formatCalendarNumericDay(datum.on);

              return (
                <button
                  aria-label={value === null ? date : `${date}, ${value}`}
                  className={styles.hit}
                  key={`hit-${point.index}`}
                  onBlur={() => setActiveIndex(null)}
                  onFocus={() => setActiveIndex(point.index)}
                  onMouseDown={(event) => event.preventDefault()}
                  onMouseEnter={() => setActiveIndex(point.index)}
                  style={{
                    left: `${(point.x / AREA.width) * 100}%`,
                    top: `${(point.y / AREA.height) * 100}%`,
                  }}
                  tabIndex={-1}
                  type="button"
                />
              );
            })}
            {hover === null ? null : (
              <>
                <span
                  className={styles.guide}
                  style={{ left: `${(hover.plot.x / AREA.width) * 100}%` }}
                />
                <span
                  className={styles.marker}
                  style={{
                    left: `${(hover.plot.x / AREA.width) * 100}%`,
                    top: `${(hover.plot.y / AREA.height) * 100}%`,
                  }}
                />
                <div
                  className={styles.tooltip}
                  data-edge={hover.placement.edge}
                  data-side={hover.placement.side}
                  role="tooltip"
                  style={{
                    left: `${(hover.plot.x / AREA.width) * 100}%`,
                    top: `${(hover.plot.y / AREA.height) * 100}%`,
                  }}
                >
                  <time dateTime={hover.point.on}>{formatCalendarNumericDay(hover.point.on)}</time>
                  <NetWorthFigure
                    amount={hover.point.total}
                    className={styles.tooltipValue}
                    reason={hover.point.reason}
                  />
                </div>
              </>
            )}
          </div>
          <div className={styles.xAxis} role="group" aria-label={t('dashboard.chart.xAxis')}>
            {tickIndices(points.length).map((index, order, ticks) => (
              <span
                className={styles.xTick}
                data-edge={order === 0 ? 'start' : order === ticks.length - 1 ? 'end' : 'mid'}
                key={`x-${index}`}
                style={{ left: `${(plotX(index, points.length) / AREA.width) * 100}%` }}
              >
                {formatCalendarNumericDay(points[index].on)}
              </span>
            ))}
          </div>
        </div>
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
