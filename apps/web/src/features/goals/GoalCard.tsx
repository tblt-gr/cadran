import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { GoalPosition } from './GoalPosition';
import { GoalStatusBadge, NEGATIVE_GOAL_STATUSES } from './GoalStatusBadge';
import styles from './GoalsPage.module.css';

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
  const detailable = status?.kind === 'ANNUAL' && onDetail !== undefined;
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
          <p className={styles.metric}>
            {t(`goals.metrics.${goal.metric}`)}
            {' · '}
            {t(goal.direction === 'MINIMUM' ? 'goals.minimumToReach' : 'goals.ceilingNotToExceed')}
          </p>
        </div>
        <div className={styles.cardActions}>
          {status ? <GoalStatusBadge status={status} /> : null}
          {status?.provisional ? (
            <StatusBadge icon="info" tone="warning">
              {t('goals.provisional')}
            </StatusBadge>
          ) : null}
          {closed ? (
            <StatusBadge icon="archive" tone="info">
              {t('goals.closedBadge')}
            </StatusBadge>
          ) : null}
          {detailable ? (
            <InfoButton label={t('goals.details')} onClick={() => onDetail(goal)} />
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
