import type { NetWorth } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

interface FreshnessBadgeProps {
  netWorth: NetWorth;
}

/**
 * How much the published figure can be trusted, in words.
 *
 * A carried-forward valuation and a missing one are different problems and
 * are named differently. Colour repeats the message; it never carries it.
 */
export function FreshnessBadge({ netWorth }: FreshnessBadgeProps) {
  const { t } = useTranslation();

  if (netWorth.quality === 'MISSING') {
    return (
      <StatusBadge icon="alert" tone="negative">
        {t('dashboard.netWorth.freshness.missing', { count: netWorth.missingValuationCount })}
      </StatusBadge>
    );
  }

  if (netWorth.quality === 'STALE') {
    return (
      <StatusBadge icon="alert" tone="warning">
        {t('dashboard.netWorth.freshness.stale', { count: netWorth.stalestAgeDays ?? 0 })}
      </StatusBadge>
    );
  }

  return (
    <StatusBadge tone="positive">
      {t('dashboard.netWorth.freshness.current', { count: netWorth.valuedAccountCount })}
    </StatusBadge>
  );
}
