import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import styles from './ProgressGauge.module.css';

interface Props {
  /** Accessible name and spoken value, for instance "4,9 mois couverts sur 6". */
  label: string;
  /** Progress as a canonical decimal between 0 and 1; a larger value fills the bar. */
  ratio: string;
  /** Where progress should stand by now, as a decimal between 0 and 1: drawn as a vertical tick. */
  markerRatio?: string | null;
}

function clampToUnit(value: string): string {
  if (compareDecimals(value, '1') > 0) return '1';
  return compareDecimals(value, '0') < 0 ? '0' : value;
}

function percent(value: string): number {
  return decimalRatioForGeometry(clampToUnit(value), '1') * 100;
}

/** A thin gold bar filled to a ratio: geometry only, the exact figures stay in the surrounding text. */
export function ProgressGauge({ label, ratio, markerRatio = null }: Props) {
  const filled = clampToUnit(ratio);
  return (
    <div
      aria-label={label}
      aria-valuemax={1}
      aria-valuemin={0}
      aria-valuenow={Number(filled)}
      aria-valuetext={label}
      className={styles.gauge}
      role="meter"
    >
      <div className={styles.track}>
        <div className={styles.fill} style={{ width: `${percent(filled)}%` }} />
      </div>
      {markerRatio === null ? null : (
        <div
          aria-hidden="true"
          className={styles.marker}
          data-testid="gauge-marker"
          style={{ left: `${percent(markerRatio)}%` }}
        />
      )}
    </div>
  );
}
