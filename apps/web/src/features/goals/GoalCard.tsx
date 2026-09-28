import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { formatGoalMonth } from './goalMonth';
import styles from './GoalsPage.module.css';

interface Props {
  goal: GoalSummary;
  onEdit: (goal: GoalSummary) => void;
  onClose: (goal: GoalSummary) => void;
}
export function GoalCard({ goal, onEdit, onClose }: Props) {
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
    </article>
  );
}
