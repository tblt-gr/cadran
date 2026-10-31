import type { PlotPoint } from '@/components/ui/charts/curve-chart/plotGeometry';
import styles from './SeriesCursor.module.css';

/** One full-plot target avoids overlapping daily targets; arrow keys still visit every value. */
export function SeriesCursor({
  label,
  labels,
  placed,
  activeIndex,
  onChange,
  plotWidth,
}: {
  label: string;
  labels: string[];
  placed: PlotPoint[];
  activeIndex: number | null;
  onChange: (index: number | null) => void;
  plotWidth: number;
}) {
  if (!placed.length) return null;
  const position = Math.max(
    0,
    placed.findIndex((point) => point.index === activeIndex),
  );
  return (
    <div
      className={styles.cursor}
      role="slider"
      tabIndex={0}
      aria-label={label}
      aria-orientation="horizontal"
      aria-valuemin={0}
      aria-valuemax={placed.length - 1}
      aria-valuenow={position}
      aria-valuetext={labels[placed[position].index]}
      onFocus={() => onChange(placed[position].index)}
      onBlur={() => onChange(null)}
      onPointerDown={(event) => {
        event.currentTarget.focus({ preventScroll: true });
        const rect = event.currentTarget.getBoundingClientRect();
        if (!rect.width) return;
        const x = ((event.clientX - rect.left) / rect.width) * plotWidth;
        const nearest = placed.reduce((best, point) =>
          Math.abs(point.x - x) < Math.abs(best.x - x) ? point : best,
        );
        onChange(nearest.index);
      }}
      onKeyDown={(event) => {
        const next =
          event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? placed.length - 1
              : event.key === 'ArrowRight' || event.key === 'ArrowUp'
                ? Math.min(position + 1, placed.length - 1)
                : event.key === 'ArrowLeft' || event.key === 'ArrowDown'
                  ? Math.max(position - 1, 0)
                  : null;
        if (next !== null) {
          event.preventDefault();
          onChange(placed[next].index);
        }
      }}
    />
  );
}
