import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';

export interface GoalRowHandlers {
  onEdit: (goal: GoalSummary) => void;
  onHistory: (goal: GoalSummary) => void;
  onStop: (goal: GoalSummary) => void;
  onDelete: (goal: GoalSummary) => void;
}

/** Edit, history, stop and delete of one goal, in the shared row menu. */
export function GoalRowActions({
  goal,
  handlers,
}: {
  goal: GoalSummary;
  handlers: GoalRowHandlers;
}) {
  const { t } = useTranslation();
  const { label } = goal;
  return (
    <ActionMenu
      label={t('goals.actionsFor', { label })}
      items={[
        {
          id: 'edit',
          icon: 'edit',
          label: t('goals.editGoal', { label }),
          text: t('goals.edit'),
          onSelect: () => handlers.onEdit(goal),
        },
        {
          id: 'history',
          icon: 'calendar',
          label: t('goals.history.openFor', { label }),
          text: t('goals.history.open'),
          onSelect: () => handlers.onHistory(goal),
        },
        {
          id: 'stop',
          icon: 'archive',
          label: t('goals.panel.stop.openFor', { label }),
          text: t('goals.panel.stop.submit'),
          onSelect: () => handlers.onStop(goal),
        },
        {
          id: 'delete',
          icon: 'delete',
          label: t('goals.panel.delete.openFor', { label }),
          text: t('goals.panel.delete.submit'),
          onSelect: () => handlers.onDelete(goal),
        },
      ]}
    />
  );
}
