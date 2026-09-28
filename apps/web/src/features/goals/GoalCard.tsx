import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { GoalScheduleTable } from './goal-schedule-table/GoalScheduleTable';
import { GoalProgressBar } from './GoalProgressBar';
import { GoalStatusBadge } from './GoalStatusBadge';
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
  /** Omitted when no month of the displayed year can be detailed yet (a future year). */
  onDetail?: (goal: GoalSummary) => void;
}
export function GoalCard({ goal, onEdit, onClose, onDetail }: Props) {
  const { t, i18n } = useTranslation();
  const definition = goal.currentVersion;
  const value = definition.targetAmount
    ? formatAmount(definition.targetAmount, goal.assetCode, i18n.language)
    : definition.ratio
      ? formatRatioPercentage(definition.ratio, i18n.language)
      : null;
  const period =
    goal.kind === 'ANNUAL' && definition.year !== null
      ? t('goals.annualPeriod', { year: definition.year })
      : t('goals.rollingPeriod', {
          month: formatGoalMonth(definition.effectiveFrom, i18n.language),
        });
  const closed = goal.state === 'CLOSED';
  return (
    <article className={`card ${styles.goalCard}`}>
      <div className={styles.cardHeading}>
        <div>
          <p className={styles.metric}>{t(`goals.metrics.${goal.metric}`)}</p>
          <h3>{goal.label}</h3>
        </div>
        {closed ? (
          <StatusBadge icon="archive" tone="info">
            {t('goals.closedBadge')}
          </StatusBadge>
        ) : (
          <ActionMenu
            label={t('goals.actionsFor', { label: goal.label })}
            items={[
              {
                id: 'edit',
                icon: 'edit',
                label: t('goals.editGoal', { label: goal.label }),
                text: t('goals.edit'),
                onSelect: () => onEdit(goal),
              },
              {
                id: 'close',
                icon: 'archive',
                label: t('goals.closeGoal', { label: goal.label }),
                text: t('goals.close'),
                onSelect: () => onClose(goal),
              },
            ]}
          />
        )}
      </div>
      {goal.status ? (
        <>
          <p className={styles.statusMonth}>
            {t('goals.statusMonth', { month: formatGoalMonth(goal.status.month, i18n.language) })}
          </p>
          <GoalStatusBadge status={goal.status} />
          {goal.status.reason !== null ? (
            <span className={styles.statusReason}>{t(`goals.reasons.${goal.status.reason}`)}</span>
          ) : null}
          {goal.status.kind === 'ANNUAL' ? <GoalProgressBar status={goal.status} /> : null}
          <dl className={styles.statusFacts}>
            <div>
              <dt>{t('goals.achieved')}</dt>
              <dd>
                {(goal.status.kind === 'ANNUAL' ? goal.status.achieved : goal.status.actual) ===
                null ? (
                  <EmptyValue
                    label={t(`goals.reasons.${goal.status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                  />
                ) : (
                  formatAmount(
                    (goal.status.kind === 'ANNUAL' ? goal.status.achieved : goal.status.actual)!,
                    goal.assetCode,
                    i18n.language,
                  )
                )}
              </dd>
            </div>
            <div>
              <dt>
                {goal.status.kind === 'ANNUAL'
                  ? t('goals.expected')
                  : t(goal.direction === 'CEILING' ? 'goals.limit' : 'goals.target')}
              </dt>
              <dd>
                {(goal.status.kind === 'ANNUAL' ? goal.status.expected : goal.status.target) ===
                null ? (
                  <EmptyValue
                    label={t(`goals.reasons.${goal.status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                  />
                ) : (
                  formatAmount(
                    (goal.status.kind === 'ANNUAL' ? goal.status.expected : goal.status.target)!,
                    goal.assetCode,
                    i18n.language,
                  )
                )}
              </dd>
            </div>
            {goal.status.kind === 'ANNUAL' ? (
              <div>
                <dt>{hasExcess(goal.status.excess) ? t('goals.excess') : t('goals.remaining')}</dt>
                <dd>
                  {(hasExcess(goal.status.excess) ? goal.status.excess : goal.status.remaining) ===
                  null ? (
                    <EmptyValue
                      label={t(`goals.reasons.${goal.status.reason ?? 'MISSING_MONTH_VALUE'}`)}
                    />
                  ) : (
                    formatAmount(
                      (hasExcess(goal.status.excess) ? goal.status.excess : goal.status.remaining)!,
                      goal.assetCode,
                      i18n.language,
                    )
                  )}
                </dd>
              </div>
            ) : null}
          </dl>
          {goal.status.provisional ? (
            <StatusBadge icon="info" tone="warning">
              {t('goals.provisional')}
            </StatusBadge>
          ) : null}
          {goal.status.kind === 'ANNUAL' && onDetail ? (
            <button className="secondary-action" onClick={() => onDetail(goal)} type="button">
              {t('goals.details')}
            </button>
          ) : null}
        </>
      ) : null}
      <div className={styles.value}>
        <span className={styles.direction}>
          {t(goal.direction === 'MINIMUM' ? 'goals.minimum' : 'goals.ceiling')}
        </span>
        <strong className="money">
          {value === null ? (
            <EmptyValue label={t('goals.noValue')} />
          ) : (
            <MoneyValue value={value} />
          )}
        </strong>
      </div>
      <dl className={styles.facts}>
        <div>
          <dt className="sr-only">{t('goals.kind')}</dt>
          <dd>{period}</dd>
        </div>
        <div>
          <dt className="sr-only">{t('goals.scope')}</dt>
          <dd>
            {t(`goals.scopeTypes.${goal.scope.type}`)} · {goal.scope.label}
          </dd>
        </div>
      </dl>
      {goal.warnings.includes('REFERENCE_ARCHIVED') ? (
        <div>
          <StatusBadge icon="alert" tone="warning">
            {t('goals.referenceArchived')}
          </StatusBadge>
        </div>
      ) : null}
      {goal.kind === 'ANNUAL' ? (
        <details className={styles.scheduleDetails}>
          <summary>{t('goals.schedule.table.caption')}</summary>
          <GoalScheduleTable assetCode={goal.assetCode} schedule={definition.schedule} />
        </details>
      ) : null}
    </article>
  );
}
