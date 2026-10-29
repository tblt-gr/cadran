import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ProgressGauge } from '@/components/ui/progress-gauge/ProgressGauge';
import { compareDecimals, decimalRatio } from '@/lib/decimal';
import { formatMonths } from './formatMonths';

/** Covered months against the target, filled up to the target; hidden when not calculable. */
export function CoverageGauge({ coverage }: { coverage: EmergencyFundCoverage }) {
  const { t, i18n } = useTranslation();
  const display = coverage.coveredMonthsDisplay;
  if (display === null || compareDecimals(coverage.targetMonths, '0') <= 0) return null;
  return (
    <ProgressGauge
      label={t('goals.emergencyFund.meterLabel', {
        value: formatMonths(display, i18n.language),
        target: formatMonths(coverage.targetMonths, i18n.language),
      })}
      ratio={decimalRatio(display, coverage.targetMonths, 6)}
    />
  );
}
