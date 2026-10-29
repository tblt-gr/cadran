import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import { formatMonths } from './formatMonths';
import styles from './CoverageGauge.module.css';

/** Covered months against the target, filled up to the target; hidden when not calculable. */
export function CoverageGauge({ coverage }: { coverage: EmergencyFundCoverage }) {
  const { t, i18n } = useTranslation();
  const display = coverage.coveredMonthsDisplay;
  if (display === null || compareDecimals(coverage.targetMonths, '0') <= 0) return null;
  const capped =
    compareDecimals(display, coverage.targetMonths) > 0 ? coverage.targetMonths : display;
  const width = decimalRatioForGeometry(capped, coverage.targetMonths) * 100;
  const label = t('goals.emergencyFund.meterLabel', {
    value: formatMonths(display, i18n.language),
    target: formatMonths(coverage.targetMonths, i18n.language),
  });
  return (
    // Geometry only: the exact figures are displayed as text from the API strings.
    <div
      aria-label={label}
      aria-valuemax={Number(coverage.targetMonths)}
      aria-valuemin={0}
      aria-valuenow={Number(capped)}
      aria-valuetext={label}
      className={styles.track}
      role="meter"
    >
      <div className={styles.fill} style={{ width: `${width}%` }} />
    </div>
  );
}
