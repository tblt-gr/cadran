import type { GoalDetail } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { goalRecurrence } from '@/features/goals/goal-panel/goalRecurrence';
import styles from './GoalDetail.module.css';

export function GoalDetailIdentity({ goal, period }: { goal: GoalDetail | null; period: string }) {
  const { t, i18n } = useTranslation();
  const state =
    goal === null
      ? null
      : period < goal.startPeriod
        ? 'future'
        : goal.endPeriod !== null && period > goal.endPeriod
          ? 'ended'
          : 'applicable';
  const values = [
    [t('goals.label'), goal?.label],
    [t('goals.metricLabel'), goal ? t(`goals.metric.${goal.metric}`) : null],
    [
      t('goals.scope'),
      goal
        ? goal.scope.type === 'WORKSPACE'
          ? t('goals.panel.scopeWorkspace')
          : goal.scope.label
        : null,
    ],
    [t('goals.detail.periodicity'), goal ? t(`goals.detail.${goal.periodicity}`) : null],
    [t('goals.direction'), goal ? t(`goals.directions.${goal.direction}`) : null],
    [t('goals.detail.applicability'), goal ? goalRecurrence(goal, t, i18n.language) : null],
    [t('goals.detail.lifecycle'), state ? t(`goals.detail.${state}`) : null],
  ];
  return (
    <section className={styles.section}>
      <h3>{t('goals.detail.identity')}</h3>
      <dl className={styles.values}>
        {values.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{goal === null ? <SkeletonBlock className={styles.block} /> : value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
