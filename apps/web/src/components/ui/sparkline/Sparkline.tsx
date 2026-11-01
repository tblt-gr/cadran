import styles from './Sparkline.module.css';

const SPARKLINE_WIDTH = 96;
const SPARKLINE_HEIGHT = 28;
const PADDING = 3;

interface SparklinePoint {
  label: string;
  value: string | null;
}

interface SparklineProps {
  /** Accessible name; the figure itself is written by the surrounding text. */
  label: string;
  points: SparklinePoint[];
}

/** The longest run of values that starts at the first non-null point. */
function leadingRun(points: SparklinePoint[]): number[] {
  const run: number[] = [];
  for (const point of points) {
    if (point.value === null) {
      if (run.length > 0) break;
      continue;
    }
    run.push(Number(point.value));
  }
  return run;
}

/**
 * Axis-less trend line drawn from server index strings. Geometry only: the displayed figures are
 * never derived here.
 */
export function Sparkline({ label, points }: SparklineProps) {
  const run = leadingRun(points);
  if (run.length < 2) return null;

  const min = Math.min(...run);
  const max = Math.max(...run);
  const span = max - min;
  const step = (SPARKLINE_WIDTH - PADDING * 2) / (run.length - 1);
  const coordinates = run.map((value, index) => ({
    x: PADDING + index * step,
    y:
      span === 0
        ? SPARKLINE_HEIGHT / 2
        : PADDING + ((max - value) / span) * (SPARKLINE_HEIGHT - PADDING * 2),
  }));
  const path = coordinates
    .map(({ x, y }, index) => `${index === 0 ? 'M' : 'L'} ${x.toFixed(2)} ${y.toFixed(2)}`)
    .join(' ');
  const last = coordinates[coordinates.length - 1]!;

  return (
    <svg
      aria-label={label}
      className={styles.sparkline}
      height={SPARKLINE_HEIGHT}
      role="img"
      viewBox={`0 0 ${SPARKLINE_WIDTH} ${SPARKLINE_HEIGHT}`}
      width={SPARKLINE_WIDTH}
    >
      <path className={styles.line} d={path} fill="none" strokeWidth="1.5" />
      <circle className={styles.dot} cx={last.x} cy={last.y} r="3" />
    </svg>
  );
}
