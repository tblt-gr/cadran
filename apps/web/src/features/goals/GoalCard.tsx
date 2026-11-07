import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { isGoalEnded } from './goalFormFields';
import { goalPeriodLabel } from './goalPeriod';
import { GoalPosition } from './GoalPosition';
import { GoalStatusBadge, NEGATIVE_GOAL_STATUSES } from './GoalStatusBadge';
import styles from './GoalsPage.module.css';

interface Props {
  goal: GoalSummary;
  onEdit: (goal: GoalSummary) => void;
  onHistory?: (goal: GoalSummary) => void;
  /** The current workspace-local month (`YYYY-MM`), which tells whether the goal has ended. */
  currentMonth: string;
  onDetail?: (goal: GoalSummary) => void;
}
export function GoalCard({ goal, currentMonth, onEdit, onDetail, onHistory }: Props) {
  const { t, i18n } = useTranslation();
  const definition = goal.currentVersion;
  const value = definition.targetAmount
    ? formatAmount(definition.targetAmount, goal.assetCode, i18n.language)
    : definition.ratio
      ? formatRatioPercentage(definition.ratio, i18n.language)
      : null;
  const ended = isGoalEnded(goal, currentMonth);
  const status = goal.status;
  // An ended goal fades back; one that ended without meeting its target keeps a discreet red edge.
  const failed =
    ended &&
    status != null &&
    status.status !== 'NOT_CALCULABLE' &&
    NEGATIVE_GOAL_STATUSES.includes(status.status);
  const detailable = status != null && onDetail !== undefined;
  return (
    <article
      className={`card ${styles.goalCard} ${ended ? styles.closedCard : ''} ${failed ? styles.failedCard : ''}`}
      data-failed={failed || undefined}
    >
      <div className={styles.cardHeading}>
        <div className={styles.goalIdentity}>
          <h3>{goal.label}</h3>
          <p className={styles.metric}>
            {t(`goals.metrics.${goal.metric}`)}
            {' · '}
            {t(goal.direction === 'MINIMUM' ? 'goals.minimumToReach' : 'goals.ceilingNotToExceed')}
          </p>
          <p className={styles.metric}>{goalPeriodLabel(goal, t, i18n.language)}</p>
        </div>
        <div className={styles.cardActions}>
          {status ? <GoalStatusBadge status={status} /> : null}
          {status?.provisional ? (
            <StatusBadge icon="info" tone="warning">
              {t('goals.provisional')}
            </StatusBadge>
          ) : null}
          {ended ? (
            <StatusBadge icon="archive" tone="info">
              {t('goals.endedBadge')}
            </StatusBadge>
          ) : null}
          {detailable ? (
            <InfoButton label={t('goals.details')} onClick={() => onDetail(goal)} />
          ) : null}
          {!ended || onHistory ? (
            <ActionMenu
              label={t('goals.actionsFor', { label: goal.label })}
              items={[
                ...(!ended
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
              ]}
            />
          ) : null}
        </div>
      </div>
      {status ? (
        <>
          <GoalPosition assetCode={goal.assetCode} status={status} />
          {status.reason !== null ? (
            <p className={styles.statusReason}>{t(`goals.reasons.${status.reason}`)}</p>
          ) : null}
        </>
      ) : (
        <p className={styles.amounts}>
          <strong className="money">
            {value === null ? (
              <EmptyValue label={t('goals.noValue')} />
            ) : (
              <MoneyValue value={value} />
            )}
          </strong>
        </p>
      )}
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
