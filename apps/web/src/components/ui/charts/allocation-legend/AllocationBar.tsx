import { svgColor } from '@/lib/svg-color';
import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import styles from './AllocationLegend.module.css';

interface AllocationBarProps {
  percent: string | null;
  color?: string;
}

/**
 * The width of one allocation bar.
 *
 * The bar is decorative and hidden from assistive technology: the same
 * percentage is already written beside it as the canonical string the backend
 * produced. A share outside 0–100 % — a group weighed against a small eligible
 * base — is clamped so the bar cannot overflow its track.
 */
export function AllocationBar({ color, percent }: AllocationBarProps) {
  if (percent === null) {
    return <span className={styles.track} aria-hidden="true" />;
  }

  const width =
    compareDecimals(percent, '0') <= 0
      ? 0
      : compareDecimals(percent, '100') >= 0
        ? 100
        : decimalRatioForGeometry(percent, '100') * 100;

  return (
    <span className={styles.track} aria-hidden="true">
      {color === undefined ? (
        <span className={styles.fill} style={{ width: `${width}%` }} />
      ) : (
        <svg
          className={styles.colorFill}
          width={`${width}%`}
          height="100%"
          preserveAspectRatio="none"
        >
          <rect fill={svgColor(color)} height="100%" width="100%" />
        </svg>
      )}
    </span>
  );
}
