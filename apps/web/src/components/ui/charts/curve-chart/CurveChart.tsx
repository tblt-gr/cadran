import type { ReactNode } from 'react';
import { useState } from 'react';
import { plotRuns, smoothPath, type PlotArea, type PlotPoint } from './plotGeometry';
import styles from './CurveChart.module.css';

const AREA: PlotArea = { height: 170, padding: 16, width: 620 };

export interface CurvePoint {
  /** Text announced for the point's focus target, e.g. "12/03/2026, 1 000,00 €". */
  hitLabel: string;
  key: string;
  /** Short label under the axis when the point is one of the ticks. */
  axisLabel: string;
  /**
   * Exact decimal string used only to place the point; null breaks the line so a
   * gap reads as a gap and never as a fall to zero.
   */
  geometryValue: string | null;
  /** Heading of the hover tooltip. */
  tooltipTitle: ReactNode;
  /** The exact figure, or an empty-value marker, shown on the axis and in the tooltip. */
  value: ReactNode;
}

interface CurveChartProps {
  /** Accessible name of the drawing. */
  label: string;
  /** Name of the group of value labels on the vertical axis. */
  valueAxisLabel: string;
  /** Name of the group of labels on the horizontal axis. */
  timeAxisLabel: string;
  points: CurvePoint[];
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

/**
 * A smoothed area curve with value labels on its extremes, a hover/focus tooltip and a
 * few time ticks. Purely presentational: the caller supplies exact strings for placement
 * and ready-made nodes for every visible figure.
 */
export function CurveChart({ label, points, timeAxisLabel, valueAxisLabel }: CurveChartProps) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const runs = plotRuns(
    points.map((point) => point.geometryValue),
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
    <div className={styles.frame}>
      <div className={styles.yAxis} role="group" aria-label={valueAxisLabel}>
        <span aria-hidden="true" className={styles.ySizer}>
          {yTicks.map((tick) => (
            <span key={`y-size-${tick.index}`}>{points[tick.index].value}</span>
          ))}
        </span>
        {yTicks.map((tick) => (
          <span
            className={styles.yTick}
            key={`y-${tick.index}`}
            style={{ top: `${(tick.y / AREA.height) * 100}%` }}
          >
            {points[tick.index].value}
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
          aria-label={label}
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
        {placed.map((point) => (
          <button
            aria-label={points[point.index].hitLabel}
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
        ))}
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
              <span className={styles.tooltipTitle}>{hover.point.tooltipTitle}</span>
              <span className={styles.tooltipValue}>{hover.point.value}</span>
            </div>
          </>
        )}
      </div>
      <div className={styles.xAxis} role="group" aria-label={timeAxisLabel}>
        {tickIndices(points.length).map((index, order, ticks) => (
          <span
            className={styles.xTick}
            data-edge={order === 0 ? 'start' : order === ticks.length - 1 ? 'end' : 'mid'}
            key={`x-${index}`}
            style={{ left: `${(plotX(index, points.length) / AREA.width) * 100}%` }}
          >
            {points[index].axisLabel}
          </span>
        ))}
      </div>
    </div>
  );
}
