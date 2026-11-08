import type { GoalEvaluation, GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';

/** Names what a goal measures: its category, axis or account, or the whole workspace. */
export function useGoalScopeLabel() {
  const { t } = useTranslation();
  return (evaluation: Pick<GoalEvaluation, 'scopeType'>, summary: GoalSummary | null): string =>
    evaluation.scopeType === 'WORKSPACE'
      ? t('goals.panel.scopeWorkspace')
      : (summary?.scope.label ?? '');
}
