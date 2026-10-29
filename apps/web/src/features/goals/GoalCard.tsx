import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { GoalProgressBar } from './GoalProgressBar';
import { GoalStatusBadge, NEGATIVE_GOAL_STATUSES } from './GoalStatusBadge';
import { formatGoalMonth } from './goalMonth';
import styles from './GoalsPage.module.css';

function isExactZero(value: string | null): boolean {
  return value !== null && /^-?0(?:\.0+)?$/.test(value);
}

/** "Dépassement" only applies to a real, non-zero excess; a null (non-calculable) or zero excess reads as "Reste". */
function hasExcess(excess: string | null): boolean {
  return excess !== null && !isExactZero(excess);
}

interface Props {
  goal: GoalSummary;
  onEdit: (goal: GoalSummary) => void;
  onClose: (goal: GoalSummary) => void;
  onHistory?: (goal: GoalSummary) => void;
  /** Omitted when no month of the displayed year can be detailed yet (a future year). */
  onDetail?: (goal: GoalSummary) => void;
}
export function GoalCard({ goal, onEdit, onClose, onDetail, onHistory }: Props) {
  const { t, i18n } = useTranslation();
  const definition = goal.currentVersion;
  const value = definition.targetAmount
    ? formatAmount(definition.targetAmount, goal.assetCode, i18n.language)
    : definition.ratio
      ? formatRatioPercentage(definition.ratio, i18n.language)
      : null;
  const closed = goal.state === 'CLOSED';
  const status = goal.status;
  // A closed goal fades back; one that closed without meeting its target keeps a discreet red edge.
  const failed =
    closed &&
    status != null &&
    status.status !== 'NOT_CALCULABLE' &&
    NEGATIVE_GOAL_STATUSES.includes(status.status);
  return (
    <article
      className={`card ${styles.goalCard} ${closed ? styles.closedCard : ''} ${failed ? styles.failedCard : ''}`}
      data-failed={failed || undefined}
    >
      <div className={styles.cardHeading}>
        <div className={styles.goalIdentity}>
          <h3
            aria-label={
              goal.kind === 'ANNUAL' && definition.year !== null
                ? t('goals.titleWithYear', { label: goal.label, year: definition.year })
                : undefined
            }
          >
            <span>{goal.label}</span>
            {goal.kind === 'ANNUAL' && definition.year !== null ? (
              <span className={styles.goalYear}> ({definition.year})</span>
            ) : null}
          </h3>
          <p className={styles.metric}>{t(`goals.metrics.${goal.metric}`)}</p>
        </div>
        {closed ? (
          <StatusBadge icon="archive" tone="info">
            {t('goals.closedBadge')}
          </StatusBadge>
        ) : null}
        {!closed || onHistory ? (
          <ActionMenu
            label={t('goals.actionsFor', { label: goal.label })}
            items={[
              ...(!closed
                ? [
                    {
                      id: 'edit',
                      icon: 'edit' as const,
                      label: t('goals.editGoal', { label: goal.label }),
                      text: t('goals.edit'),
                      onSelect: () => onEdit(goal),
                    },
                  ]
                : []),
              ...(onHistory
                ? [
                    {
                      id: 'history',
                      icon: 'calendar' as const,
                      label: t('goals.history.openFor', { label: goal.label }),
                      text: t('goals.history.open'),
                      onSelect: () => onHistory(goal),
                    },
                  ]
                : []),
              ...(!closed
                ? [
                    {
                      id: 'close',
                      icon: 'archive' as const,
                      label: t('goals.closeGoal', { label: goal.label }),
                      text: t('goals.close'),
                      onSelect: () => onClose(goal),
                    },
                  ]
                : []),
            ]}
          />
        ) : null}
      </div>
      <div className={styles.value}>
        <span className={styles.direction}>
          {t(goal.direction === 'MINIMUM' ? 'goals.minimumToReach' : 'goals.ceilingNotToExceed')}
        </span>
        <strong className="money">
          {value === null ? (
            <EmptyValue label={t('goals.noValue')} />
          ) : (
            <MoneyValue value={value} />
          )}
        </strong>
      </div>
      {status ? (
        <>
          <div className={styles.statusHeading}>
            <p className={styles.statusMonth}>
              {t('goals.statusMonth', { month: formatGoalMonth(status.month, i18n.language) })}
            </p>
            <GoalStatusBadge status={status} />
          </div>
          {status.reason !== null ? (
            <span className={styles.statusReason}>{t(`goals.reasons.${status.reason}`)}</span>
          ) : null}
          {status.kind === 'ANNUAL' && status.progressRatioDisplay !== null ? (
            <GoalProgressBar assetCode={goal.assetCode} status={status} />
          ) : null}
          {status.kind !== 'ANNUAL' || status.progressRatioDisplay === null ? (
            <dl className={styles.statusFacts}>
              <div>
                <dt>
                  {t(status.kind === 'ANNUAL' ? 'goals.achievedToDate' : 'goals.achievedThisMonth')}
                </dt>
                <dd>
                  {(status.kind === 'ANNUAL' ? status.achieved : status.actual) === null ? (
                    <EmptyValue
                      label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                    />
                  ) : (
                    formatAmount(
                      (status.kind === 'ANNUAL' ? status.achieved : status.actual)!,
                      goal.assetCode,
                      i18n.language,
                    )
                  )}
                </dd>
              </div>
              <div>
                <dt>
                  {status.kind === 'ANNUAL'
                    ? t('goals.expectedToDate')
                    : t(goal.direction === 'CEILING' ? 'goals.limit' : 'goals.target')}
                </dt>
                <dd>
                  {(status.kind === 'ANNUAL' ? status.expected : status.target) === null ? (
                    <EmptyValue
                      label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                    />
                  ) : (
                    formatAmount(
                      (status.kind === 'ANNUAL' ? status.expected : status.target)!,
                      goal.assetCode,
                      i18n.language,
                    )
                  )}
                </dd>
              </div>
              {status.kind === 'ANNUAL' ? (
                <div>
                  <dt>
                    {t(
                      hasExcess(status.excess)
                        ? goal.direction === 'CEILING'
                          ? 'goals.excessOverCeiling'
                          : 'goals.excessOverTarget'
                        : goal.direction === 'CEILING'
                          ? 'goals.remainingBeforeCeiling'
                          : 'goals.remainingToTarget',
                    )}
                  </dt>
                  <dd>
                    {(hasExcess(status.excess) ? status.excess : status.remaining) === null ? (
                      <EmptyValue
                        label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                      />
                    ) : (
                      formatAmount(
                        (hasExcess(status.excess) ? status.excess : status.remaining)!,
                        goal.assetCode,
                        i18n.language,
                      )
                    )}
                  </dd>
                </div>
              ) : null}
            </dl>
          ) : null}
          {status.provisional || (status.kind === 'ANNUAL' && onDetail) ? (
            <div className={styles.statusActions}>
              {status.provisional ? (
                <StatusBadge icon="info" tone="warning">
                  {t('goals.provisional')}
                </StatusBadge>
              ) : null}
              {status.kind === 'ANNUAL' && onDetail ? (
                <button className="secondary-action" onClick={() => onDetail(goal)} type="button">
                  {t('goals.details')}
                </button>
              ) : null}
            </div>
          ) : null}
        </>
      ) : null}
      {goal.warnings.includes('REFERENCE_ARCHIVED') ? (
        <div>
          <StatusBadge icon="alert" tone="warning">
            {t('goals.referenceArchived')}
          </StatusBadge>
        </div>
      ) : null}
    </article>
  );
}
