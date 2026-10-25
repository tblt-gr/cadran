import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountNameCell.module.css';

/** The same name and status slots serve loaded rows and their skeletons. */
export function AccountNameCell({ account }: { account: Account | null }) {
  const { t } = useTranslation();
  const status = account?.isPrimary
    ? t('accounts.primary.badge')
    : account && account.status !== 'ACTIVE'
      ? t(`accounts.statuses.${account.status}`)
      : null;

  return (
    <div className={styles.labelCell}>
      <a
        className={styles.accountLink}
        href={account ? `/accounts/${account.id}` : undefined}
        onClick={
          account ? (event) => handleClientNavigation(event, `/accounts/${account.id}`) : undefined
        }
      >
        {account ? account.label : <SkeletonBlock className={styles.nameSkeleton} />}
      </a>
      <span hidden={account !== null && status === null}>
        <StatusBadge tone={account?.status === 'ARCHIVED' ? 'warning' : 'info'}>
          {account ? status : <SkeletonBlock className={styles.statusSkeleton} />}
        </StatusBadge>
      </span>
    </div>
  );
}
