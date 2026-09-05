import type { NetWorthShare } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatSharePercent } from '@/lib/formatSharePercent';
import styles from './ShareCell.module.css';

interface ShareCellProps {
  share: NetWorthShare;
}

/**
 * Displays a backend-owned weight. A missing percent is a reason, never `0%`.
 */
export function ShareCell({ share }: ShareCellProps) {
  const { t } = useTranslation();

  if (share.percent === null) {
    return (
      <span className={styles.unknown}>
        {share.reason
          ? t(`accountGroups.share.reasons.${share.reason}`)
          : t('accountGroups.share.notApplicable')}
      </span>
    );
  }

  return <span className={styles.value}>{formatSharePercent(share.percent)}</span>;
}
