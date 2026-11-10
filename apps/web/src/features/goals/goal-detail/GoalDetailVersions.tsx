import type { GoalDetail, GoalMetric, GoalPeriodicity, GoalValueType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { isStockMetric } from '@/features/goals/goalFormFields';
import { GoalRecordedVersion } from './GoalRecordedVersion';
import styles from './GoalDetail.module.css';

export function GoalDetailVersions({
  goal,
  appliedVersion,
  periodicity,
  metric,
  valueType,
}: {
  goal: GoalDetail | null;
  appliedVersion: number;
  periodicity: GoalPeriodicity;
  metric: GoalMetric;
  valueType: GoalValueType;
}) {
  const { t } = useTranslation();
  const versions = goal
    ? [...goal.versions].sort(
        (a, b) => a.effectiveFrom.localeCompare(b.effectiveFrom) || a.versionNo - b.versionNo,
      )
    : [null];
  return (
    <section className={styles.section}>
      <h3>{t('goals.history.title')}</h3>
      {versions.length === 0 ? (
        <p>{t('goals.history.empty')}</p>
      ) : (
        <ol className={styles.revisions}>
          {versions.map((version) => (
            <GoalRecordedVersion
              key={version?.versionNo ?? 'pending'}
              version={version}
              assetCode={goal?.assetCode ?? 'EUR'}
              appliedVersion={appliedVersion}
              showSchedule={
                (goal?.periodicity ?? periodicity) === 'YEARLY' &&
                !isStockMetric(goal?.metric ?? metric) &&
                (version?.valueType ?? valueType) === 'AMOUNT'
              }
            />
          ))}
        </ol>
      )}
    </section>
  );
}
