import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import type { NetWorthShare } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatSharePercent } from '@/lib/formatSharePercent';
import styles from './ShareCell.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface ShareCellProps {
  share: NetWorthShare;
}

/**
 * Displays a backend-owned weight. A missing percent is a reason, never `0%`.
 */
export function ShareCell({ share }: ShareCellProps) {
  const { t } = useTranslation();

  if (share.percentDisplay === null) {
    return (
      <EmptyValue
        label={
          share.reason
            ? t(`accountGroups.share.reasons.${share.reason}`)
            : t('accountGroups.share.notApplicable')
        }
      />
    );
  }

  return <MoneyValue className={styles.value} value={formatSharePercent(share.percentDisplay)} />;
}
