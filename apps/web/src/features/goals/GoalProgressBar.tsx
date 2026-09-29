import type { GoalAnnualStatus, GoalAnnualStatusSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import styles from './GoalsPage.module.css';

function hasExcess(excess: string | null): boolean {
  return excess !== null && !/^-?0(?:\.0+)?$/.test(excess);
}

export function GoalProgressBar({
  status,
  assetCode,
}: {
  status: GoalAnnualStatus | GoalAnnualStatusSummary;
  assetCode: string;
}) {
  const { t, i18n } = useTranslation();
  if (status.progressRatioDisplay === null) return null;
  const label = t('goals.progressLabel', {
    value: formatRatioPercentage(status.progressRatioDisplay, i18n.language),
  });
  const exceeds = hasExcess(status.excess);
  const remainingLabel = t(
    exceeds
      ? status.direction === 'CEILING'
        ? 'goals.excessOverCeiling'
        : 'goals.excessOverTarget'
      : status.direction === 'CEILING'
        ? 'goals.remainingBeforeCeiling'
        : 'goals.remainingToTarget',
  );
  const remainingValue = exceeds ? status.excess : status.remaining;
  return (
    <div className={styles.progress}>
      <div className={styles.progressHeading}>
        <p className={styles.progressAmount}>
          <span>{t('goals.achievedToDate')}</span>
          <strong className="money">
            {status.achieved === null ? (
              <EmptyValue label={t('goals.noData')} />
            ) : (
              formatAmount(status.achieved, assetCode, i18n.language)
            )}
          </strong>
        </p>
        <label htmlFor={`goal-progress-${status.goalId}`}>{label}</label>
      </div>
      <meter
        id={`goal-progress-${status.goalId}`}
        max={1}
        min={0}
        value={status.progressRatioDisplay as unknown as number}
      />
      <dl className={styles.progressFacts}>
        <div>
          <dt>{t('goals.expectedToDate')}</dt>
          <dd>
            {status.expected === null ? (
              <EmptyValue label={t('goals.noData')} />
            ) : (
              formatAmount(status.expected, assetCode, i18n.language)
            )}
          </dd>
        </div>
        <div>
          <dt>{remainingLabel}</dt>
          <dd>
            {remainingValue === null ? (
              <EmptyValue label={t('goals.noData')} />
            ) : (
              formatAmount(remainingValue, assetCode, i18n.language)
            )}
          </dd>
        </div>
      </dl>
    </div>
  );
}
