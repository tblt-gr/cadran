import type { GoalEvaluation, GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';

/** Names what a goal measures: its category, axis or account, or the whole workspace. */
export function useGoalScopeLabel() {
  const { t } = useTranslation();
  return (evaluation: Pick<GoalEvaluation, 'scopeType'>, summary: GoalSummary | null): string => {
    if (evaluation.scopeType === 'WORKSPACE') {
      return t('goals.panel.scopeWorkspace');
    }
    const axis = summary?.scope.axis;
    // The server label of an axis is its English API text.
    if (evaluation.scopeType === 'AXIS' && axis) {
      return t(`categories.axes.${axis}`);
    }
    return summary?.scope.label ?? '';
  };
}
