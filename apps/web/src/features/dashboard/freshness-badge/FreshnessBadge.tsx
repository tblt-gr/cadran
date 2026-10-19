import type { NetWorth } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { openMissingValuations } from '@/features/dashboard/missing-valuations/missingValuationsDialog';
import styles from './FreshnessBadge.module.css';

interface FreshnessBadgeProps {
  netWorth: NetWorth;
}

/**
 * How much the published figure can be trusted, in words.
 *
 * A carried-forward valuation and a missing one are different problems and
 * are named differently. Colour repeats the message; it never carries it.
 * A missing valuation is actionable, so that badge opens the accounts behind it.
 */
export function FreshnessBadge({ netWorth }: FreshnessBadgeProps) {
  const { t } = useTranslation();

  if (netWorth.quality === 'MISSING') {
    return (
      <button
        aria-haspopup="dialog"
        className={styles.action}
        onClick={openMissingValuations}
        title={t('dashboard.missingValuations.open')}
        type="button"
      >
        <StatusBadge icon="alert" tone="negative" visualMask>
          {t('dashboard.netWorth.freshness.missing', { count: netWorth.missingValuationCount })}
        </StatusBadge>
      </button>
    );
  }

  if (netWorth.quality === 'STALE') {
    return (
      <span data-visual-mask>
        {t('dashboard.netWorth.freshness.stale', { count: netWorth.stalestAgeDays ?? 0 })}
      </span>
    );
  }

  return (
    <span data-visual-mask>
      {t('dashboard.netWorth.freshness.current', { count: netWorth.valuedAccountCount })}
    </span>
  );
}
