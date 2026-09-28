import type { GoalStatus, GoalStatusSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

export function GoalStatusBadge({ status }: { status: GoalStatus | GoalStatusSummary }) {
  const { t } = useTranslation();
  const negative = ['BEHIND', 'EXCEEDED', 'OVER_PACE', 'MISSED', 'OVER', 'NOT_CALCULABLE'].includes(
    status.status,
  );
  return (
    <StatusBadge
      icon={negative ? 'alert' : 'goals'}
      tone={negative ? (status.status === 'NOT_CALCULABLE' ? 'warning' : 'negative') : 'positive'}
    >
      {t(`goals.statuses.${status.status}`)}
    </StatusBadge>
  );
}
