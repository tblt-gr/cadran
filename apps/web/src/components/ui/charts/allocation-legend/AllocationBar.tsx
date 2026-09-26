import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import styles from './AllocationLegend.module.css';

interface AllocationBarProps {
  percent: string | null;
}

/**
 * The width of one allocation bar.
 *
 * The bar is decorative and hidden from assistive technology: the same
 * percentage is already written beside it as the canonical string the backend
 * produced. A share outside 0–100 % — a group weighed against a small eligible
 * base — is clamped so the bar cannot overflow its track.
 */
export function AllocationBar({ percent }: AllocationBarProps) {
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
      <span className={styles.fill} style={{ width: `${width}%` }} />
    </span>
  );
}
