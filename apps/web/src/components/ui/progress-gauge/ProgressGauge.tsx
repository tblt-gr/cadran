import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import styles from './ProgressGauge.module.css';

interface Props {
  /** Accessible name and spoken value, for instance "4,9 mois couverts sur 6". */
  label: string;
  /** Progress as a canonical decimal between 0 and 1; a larger value fills the bar. */
  ratio: string;
}

/** A thin gold bar filled to a ratio: geometry only, the exact figures stay in the surrounding text. */
export function ProgressGauge({ label, ratio }: Props) {
  const filled =
    compareDecimals(ratio, '1') > 0 ? '1' : compareDecimals(ratio, '0') < 0 ? '0' : ratio;
  return (
    <div
      aria-label={label}
      aria-valuemax={1}
      aria-valuemin={0}
      aria-valuenow={Number(filled)}
      aria-valuetext={label}
      className={styles.track}
      role="meter"
    >
      <div
        className={styles.fill}
        style={{ width: `${decimalRatioForGeometry(filled, '1') * 100}%` }}
      />
    </div>
  );
}
