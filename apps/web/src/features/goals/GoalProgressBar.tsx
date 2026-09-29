import type { GoalAnnualStatus, GoalAnnualStatusSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import styles from './GoalsPage.module.css';

function scaled(value: string): bigint {
  const [whole, fraction = ''] = value.split('.');
  return BigInt(`${whole}${fraction.padEnd(24, '0')}`);
}
/**
 * `expected / target` as a 0..1 ratio, precise to six decimal places: the same shape
 * `formatRatioPercentage` and `progressRatioDisplay` already use, so it is never
 * mistaken for a value already expressed in percent.
 */
function expectedRatio(expected: string, target: string): { exact: string; marker: string } | null {
  const divisor = scaled(target);
  if (divisor <= 0n) return null;
  const scaledRatio = (scaled(expected) * 1000000n) / divisor;
  const exact = `${scaledRatio / 1000000n}.${(scaledRatio % 1000000n).toString().padStart(6, '0')}`;
  const clamped = scaledRatio < 0n ? 0n : scaledRatio > 1000000n ? 1000000n : scaledRatio;
  return {
    exact,
    marker: `${clamped / 10000n}.${(clamped % 10000n).toString().padStart(4, '0')}`,
  };
}

export function GoalProgressBar({
  status,
}: {
  status: GoalAnnualStatus | GoalAnnualStatusSummary;
}) {
  const { t, i18n } = useTranslation();
  if (status.progressRatioDisplay === null) return null;
  const label = t('goals.progressLabel', {
    value: formatRatioPercentage(status.progressRatioDisplay, i18n.language),
  });
  const expected =
    status.expected !== null && status.target !== null
      ? expectedRatio(status.expected, status.target)
      : null;
  return (
    <div className={styles.progress}>
      <label htmlFor={`goal-progress-${status.goalId}`}>{label}</label>
      <meter
        id={`goal-progress-${status.goalId}`}
        max={1}
        min={0}
        value={status.progressRatioDisplay as unknown as number}
      >
        {label}
      </meter>
      {expected ? (
        <>
          <span
            aria-hidden="true"
            className={styles.expectedTick}
            style={{ left: `${expected.marker}%` }}
          />
          <span>
            {t('goals.expectedTick', {
              value: formatRatioPercentage(expected.exact, i18n.language),
            })}
          </span>
        </>
      ) : null}
    </div>
  );
}
