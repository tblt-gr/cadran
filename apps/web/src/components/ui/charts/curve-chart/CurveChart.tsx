import { SeriesCursor } from './series-cursor/SeriesCursor';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { useMediaQuery } from '@/hooks/use-media-query';
import { datePosition, plotRuns, smoothPath, stepAfterPath, type PlotPoint } from './plotGeometry';
import { AREA, nearestByX, plotX, tickIndices, tooltipEdge, yLevels } from './plotLayout';
import styles from './CurveChart.module.css';

export interface CurvePoint {
  /** Text announced for the point's focus target, e.g. "12/03/2026, 1 000,00 €". */
  hitLabel: string;
  key: string;
  /** ISO calendar date for proportional placement in step charts. */
  date?: string;
  /** Short label under the axis when the point is one of the ticks. */
  axisLabel: string;
  /**
   * Exact decimal string used only to place the point; null breaks the line so a
   * gap reads as a gap and never as a fall to zero.
   */
  geometryValue: string | null;
  /** Heading of the hover tooltip. */
  tooltipTitle: ReactNode;
  /** Optional lines under the figure in the tooltip only, e.g. quality notes. */
  tooltipDetail?: ReactNode;
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
  interpolation?: 'smooth' | 'step-after';
  interaction?: 'points' | 'series';
  /** Drops the figures of the vertical axis, leaving the dates only; they stay in the tooltip. */
  hideValueAxis?: boolean;
  /** Reports the key of the hovered or focused point, null when the pointer or focus leaves. */
  onActivePointChange?: (key: string | null) => void;
  /** The host shows the active point itself: no tooltip, the guide and the marker stay. */
  hideTooltip?: boolean;
  /** The point a tap pinned, kept while the pointer is elsewhere; null when none is pinned. */
  selectedKey?: string | null;
  /** Called with the tapped point's key on a screen that cannot hover, where a tap pins a point. */
  onSelect?: (key: string) => void;
}

/**
 * A smoothed area curve with value labels on its extremes, a hover/focus tooltip and a
 * few time ticks. Purely presentational: the caller supplies exact strings for placement
 * and ready-made nodes for every visible figure.
 */
export function CurveChart({
  label,
  points,
  timeAxisLabel,
  valueAxisLabel,
  interpolation = 'smooth',
  interaction = 'points',
  hideValueAxis = false,
  hideTooltip = false,
  onActivePointChange,
  onSelect,
  selectedKey = null,
}: CurveChartProps) {
  // A touch screen has no hover: the emulated mouse events are ignored and a tap pins the point.
  const noHover = useMediaQuery('(hover: none)');
  const [activeIndex, setActiveIndexState] = useState<number | null>(null);
  const setActiveIndex = (index: number | null) => {
    setActiveIndexState(index);
    onActivePointChange?.(index === null ? null : (points[index]?.key ?? null));
  };
  const dates =
    interpolation === 'step-after' && points.every((point) => point.date !== undefined)
      ? points.map((point) => point.date!)
      : undefined;
  const xAt = (index: number) =>
    dates ? datePosition(dates, index, AREA.width) : plotX(index, points.length);
  const linePath = (run: PlotPoint[]) =>
    interpolation === 'step-after'
      ? stepAfterPath(
          run,
          points[run.at(-1)!.index + 1]?.geometryValue === null
            ? xAt(run.at(-1)!.index + 1)
            : undefined,
        )
      : smoothPath(run);
  const runs = plotRuns(
    points.map((point) => point.geometryValue),
    AREA,
    dates,
  );
  const placed = runs.flat();
  const yTicks = yLevels(placed);
  const selectedIndex = selectedKey === null ? -1 : points.findIndex((p) => p.key === selectedKey);
  const shownIndex = activeIndex ?? (selectedIndex >= 0 ? selectedIndex : null);
  const active =
    shownIndex === null ? null : (placed.find((point) => point.index === shownIndex) ?? null);
  const activePoint = active === null ? undefined : points[active.index];
  const hover =
    active === null || activePoint === undefined
      ? null
      : { point: activePoint, plot: active, placement: tooltipEdge(active.x, active.y) };

  return (
    <div
      className={styles.frame}
      data-no-swipe
      data-visual-mask
      data-value-axis={hideValueAxis ? 'hidden' : undefined}
      data-interpolation={interpolation === 'step-after' ? interpolation : undefined}
    >
      {hideValueAxis ? null : (
        <div className={styles.yAxis} role="group" aria-label={valueAxisLabel}>
          <span aria-hidden="true" className={styles.ySizer}>
            {yTicks.map((tick) => (
              <span key={`y-size-${tick.index}`}>{points[tick.index].value}</span>
            ))}
          </span>
          {yTicks.map((tick) => (
            <span
              className={styles.yTick}
              title={
                typeof points[tick.index].value === 'string'
                  ? (points[tick.index].value as string)
                  : undefined
              }
              key={`y-${tick.index}`}
              style={{ top: `${(tick.y / AREA.height) * 100}%` }}
            >
              {points[tick.index].value}
            </span>
          ))}
        </div>
      )}
      <div
        className={styles.plotBody}
        onClick={(event) => {
          if (!noHover || !onSelect) return;
          const index = nearestByX(
            placed,
            event.clientX,
            event.currentTarget.clientWidth,
            event.currentTarget.getBoundingClientRect().left,
          );
          const key = index === null ? undefined : points[index]?.key;
          if (key !== undefined) onSelect(key);
        }}
        onMouseLeave={() => {
          if (!noHover) setActiveIndex(null);
        }}
        onMouseMove={(event) => {
          if (noHover) return;
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
            <path d={yTicks.map((tick) => `M0 ${tick.y}H${AREA.width}`).join('')} />
          </g>
          {runs.map((run) => {
            const line = linePath(run);
            const first = run[0];
            const last = run[run.length - 1];

            return (
              <path
                className={styles.area}
                d={`${line} L${interpolation === 'step-after' && points[last.index + 1]?.geometryValue === null ? xAt(last.index + 1) : last.x},${AREA.height} L${first.x},${AREA.height} Z`}
                key={`area-${first.index}`}
              />
            );
          })}
          {runs.map((run) => (
            <path className={styles.line} d={linePath(run)} key={`line-${run[0].index}`} />
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
        {interaction === 'series' ? (
          <SeriesCursor
            label={label}
            labels={points.map((point) => point.hitLabel)}
            placed={placed}
            activeIndex={activeIndex}
            onChange={setActiveIndex}
            plotWidth={AREA.width}
          />
        ) : (
          placed.map((point) => (
            <button
              aria-label={points[point.index].hitLabel}
              className={styles.hit}
              data-chart-point
              key={`hit-${point.index}`}
              onBlur={() => setActiveIndex(null)}
              onFocus={() => setActiveIndex(point.index)}
              onMouseDown={(event) => event.preventDefault()}
              onMouseEnter={() => {
                if (!noHover) setActiveIndex(point.index);
              }}
              style={{
                left: `${(point.x / AREA.width) * 100}%`,
                top: `${(point.y / AREA.height) * 100}%`,
              }}
              tabIndex={interpolation === 'step-after' ? 0 : -1}
              type="button"
            />
          ))
        )}
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
            {hideTooltip ? null : (
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
                {hover.point.tooltipDetail ? (
                  <span className={styles.tooltipDetail}>{hover.point.tooltipDetail}</span>
                ) : null}
              </div>
            )}
          </>
        )}
      </div>
      <div className={styles.xAxis} role="group" aria-label={timeAxisLabel}>
        {tickIndices(points.length).map((index, order, ticks) => (
          <span
            className={styles.xTick}
            data-edge={order === 0 ? 'start' : order === ticks.length - 1 ? 'end' : 'mid'}
            key={`x-${index}`}
            style={{ left: `${(xAt(index) / AREA.width) * 100}%` }}
          >
            {points[index].axisLabel}
          </span>
        ))}
      </div>
    </div>
  );
}
