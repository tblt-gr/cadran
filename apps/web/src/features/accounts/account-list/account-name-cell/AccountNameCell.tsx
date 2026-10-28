import type { AccountOverviewRow } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountNameCell.module.css';

interface AccountNameCellProps {
  account: AccountOverviewRow | null;
  /** Identification color of the account primary group, for the sub-group line. */
  groupColor?: string | null;
}

/**
 * The same name and status slots serve loaded rows and their skeletons. A row listed under a
 * parent section also names its own sub-group, with that group's parent in smaller text.
 */
export function AccountNameCell({ account, groupColor = null }: AccountNameCellProps) {
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
      {account?.primaryGroupParentLabel ? (
        <span className={styles.subGroup}>
          <GroupMarker color={groupColor} />
          <span>{account.primaryGroupLabel}</span>
          <small className={styles.parentLabel}>({account.primaryGroupParentLabel})</small>
        </span>
      ) : null}
    </div>
  );
}
