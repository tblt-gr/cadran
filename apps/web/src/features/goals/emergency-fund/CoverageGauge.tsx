import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ProgressGauge } from '@/components/ui/progress-gauge/ProgressGauge';
import { compareDecimals, decimalRatio } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { formatMonths } from './formatMonths';

/** Liquid assets against the required amount, whichever way the target is set; hidden when not calculable. */
export function CoverageGauge({ coverage }: { coverage: EmergencyFundCoverage }) {
  const { t, i18n } = useTranslation();
  const liquid = coverage.liquidAssets.value;
  const required = coverage.requiredAmount;
  if (liquid === null || required === null || compareDecimals(required, '0') <= 0) return null;
  const ratio = decimalRatio(liquid, required, 6);
  const display = coverage.coveredMonthsDisplay;
  const implied = coverage.target.impliedMonths;
  return (
    <ProgressGauge
      label={
        display !== null && implied !== null
          ? t('goals.emergencyFund.meterLabel', {
              value: formatMonths(display, i18n.language),
              target: formatMonths(implied, i18n.language),
            })
          : t('goals.progressLabel', { value: formatRatioPercentage(ratio, i18n.language) })
      }
      ratio={ratio}
    />
  );
}
