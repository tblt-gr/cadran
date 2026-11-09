import type { BulkGoalItem, BulkGoalResult, GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import styles from './BulkGoal.module.css';

/** The same list and value leaves render while the server computes the preview. */
export function BulkGoalPreview({
  goals,
  result,
  pending,
}: {
  goals: GoalSummary[];
  result: BulkGoalResult | null;
  pending: boolean;
}) {
  const { t, i18n } = useTranslation();
  const figure = (item: BulkGoalItem, value: string | null) =>
    value === null
      ? t('goals.noValue')
      : item.valueType === 'AMOUNT'
        ? formatAmount(value, item.assetCode, i18n.language)
        : formatRatioPercentage(value, i18n.language);
  return (
    <section aria-busy={pending} aria-label={t('goals.bulk.preview')}>
      <h3>{t('goals.bulk.preview')}</h3>
      {pending ? (
        <p className="sr-only" role="status">
          {t('goals.bulk.previewLoading')}
        </p>
      ) : null}
      <ul className={styles.preview}>
        {goals.map((goal) => {
          const item = result?.items.find((candidate) => candidate.goalId === goal.id);
          const unitChanged = item?.conflict?.code === 'goal_target_unit_changed';
          const value = (text: string | undefined) =>
            text ?? <SkeletonBlock className={styles.block} />;
          return (
            <li key={goal.id}>
              <strong>{goal.label}</strong>
              <dl className={styles.values}>
                <div>
                  <dt>{t('goals.bulk.beforeTarget')}</dt>
                  <dd>{value(item ? figure(item, item.before.target) : undefined)}</dd>
                </div>
                <div>
                  <dt>{t('goals.bulk.afterTarget')}</dt>
                  <dd>
                    {value(item ? figure(item, unitChanged ? null : item.after.target) : undefined)}
                  </dd>
                </div>
                <div>
                  <dt>{t('goals.bulk.beforeTolerance')}</dt>
                  <dd>{value(item ? figure(item, item.before.tolerance) : undefined)}</dd>
                </div>
                <div>
                  <dt>{t('goals.bulk.afterTolerance')}</dt>
                  <dd>
                    {value(
                      item ? figure(item, unitChanged ? null : item.after.tolerance) : undefined,
                    )}
                  </dd>
                </div>
                <div>
                  <dt>{t('goals.bulk.firstMonth')}</dt>
                  <dd>{value(item?.firstAffectedMonth)}</dd>
                </div>
                <div>
                  <dt>{t('goals.bulk.lastMonth')}</dt>
                  <dd>
                    {value(item ? (item.lastAffectedMonth ?? t('goals.bulk.noEnd')) : undefined)}
                  </dd>
                </div>
              </dl>
              <p>
                {t('goals.bulk.laterRevisions')}:{' '}
                {value(
                  item
                    ? item.laterRevisions
                        .map((revision) => `${revision.effectiveFrom} (v${revision.versionNo})`)
                        .join(', ') || t('goals.bulk.none')
                    : undefined,
                )}
              </p>
              <p>
                {t('goals.bulk.overrides')}:{' '}
                {value(
                  item
                    ? item.overrides
                        .map(
                          (override) =>
                            `${override.label} (${override.startPeriod} - ${override.endPeriod ?? t('goals.bulk.noEnd')})`,
                        )
                        .join(', ') || t('goals.bulk.none')
                    : undefined,
                )}
              </p>
              {item?.conflict ? (
                <p className={styles.error} role="alert">
                  {t(`goals.bulk.conflicts.${item.conflict.code}`, {
                    defaultValue: t('goals.bulk.conflict'),
                  })}{' '}
                  (
                  {t(`goals.bulk.fields.${item.conflict.field}`, {
                    defaultValue: item.conflict.field,
                  })}
                  )
                </p>
              ) : item && !item.changed ? (
                <p>{t('goals.bulk.unchanged')}</p>
              ) : null}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
