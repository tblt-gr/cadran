import type { GoalStatus, GoalStatusSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

/** Statuses that read as a miss: shared by the badge tone and the closed-goal edge. */
export const NEGATIVE_GOAL_STATUSES: readonly string[] = [
  'BEHIND',
  'EXCEEDED',
  'OVER_PACE',
  'MISSED',
  'OVER',
  'NOT_CALCULABLE',
];

export function GoalStatusBadge({ status }: { status: GoalStatus | GoalStatusSummary }) {
  const { t } = useTranslation();
  const negative = NEGATIVE_GOAL_STATUSES.includes(status.status);
  return (
    <StatusBadge
      icon={negative ? 'alert' : 'goals'}
      tone={negative ? (status.status === 'NOT_CALCULABLE' ? 'warning' : 'negative') : 'positive'}
    >
      {t(`goals.statuses.${status.status}`)}
    </StatusBadge>
  );
}
