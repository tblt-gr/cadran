import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
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
      : '—';
  return (
    <article className={`card ${styles.goalCard}`}>
      <div className={styles.cardHeading}>
        <div>
          <p className={styles.metric}>{t(`goals.metrics.${goal.metric}`)}</p>
          <h3>{goal.label}</h3>
        </div>
        <ActionMenu
          label={t('goals.actionsFor', { label: goal.label })}
          items={[
            {
              id: 'edit',
              icon: 'edit',
              label: t('goals.editGoal', { label: goal.label }),
              text: t('goals.edit'),
              disabled: goal.state === 'CLOSED',
              onSelect: () => onEdit(goal),
            },
            {
              id: 'close',
              icon: 'archive',
              label: t('goals.closeGoal', { label: goal.label }),
              text: t('goals.close'),
              disabled: goal.state === 'CLOSED',
              onSelect: () => onClose(goal),
            },
          ]}
        />
      </div>
      <p className={styles.scope}>
        {t(`goals.scopeTypes.${goal.scope.type}`)} · {goal.scope.label}
      </p>
      <div className={styles.value}>
        <span>{t(goal.direction === 'MINIMUM' ? 'goals.minimum' : 'goals.ceiling')}</span>
        <strong>{value}</strong>
      </div>
      {goal.warnings.includes('REFERENCE_ARCHIVED') ? (
        <p className={styles.warning} role="status">
          {t('goals.referenceArchived')}
        </p>
      ) : null}
    </article>
  );
}
