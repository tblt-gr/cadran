import type { AccountOverviewRow } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountNameCell.module.css';

interface AccountNameCellProps {
  account: AccountOverviewRow | null;
  /** Identification color of the account primary group, for the sub-group line. */
  groupColor?: string | null;
  /** The warning that the row is denominated in another asset than its group total. */
  assetWarning?: string | null;
  /** `institution · product · ••1234`, each part only when present. */
  identity?: string | null;
}

/**
 * The same name and status slots serve loaded rows and their skeletons. A row listed under a
 * parent section also names its own sub-group, with that group's parent in smaller text.
 */
export function AccountNameCell({
  account,
  assetWarning = null,
  groupColor = null,
  identity = null,
}: AccountNameCellProps) {
  const { t } = useTranslation();
  const status = account?.isPrimary
    ? t('accounts.primary.badge')
    : account && account.status !== 'ACTIVE'
      ? t(`accounts.statuses.${account.status}`)
      : null;

  // An investment account's page is its portfolio: no detour through the account page.
  const href = !account
    ? undefined
    : valuedByPortfolio(account)
      ? `/portfolios/${account.id}`
      : `/accounts/${account.id}`;

  return (
    <div className={styles.labelCell}>
      <a
        className={styles.accountLink}
        href={href}
        onClick={href ? (event) => handleClientNavigation(event, href) : undefined}
      >
        {account ? account.label : <SkeletonBlock className={styles.nameSkeleton} />}
      </a>
      <span hidden={account !== null && status === null}>
        <StatusBadge tone={account?.status === 'ARCHIVED' ? 'warning' : 'info'}>
          {account ? status : <SkeletonBlock className={styles.statusSkeleton} />}
        </StatusBadge>
      </span>
      {account === null || identity ? (
        <small className={styles.identity}>
          {account ? identity : <SkeletonBlock className={styles.identitySkeleton} />}
        </small>
      ) : null}
      {account?.primaryGroupParentLabel ? (
        <span className={styles.subGroup}>
          <GroupMarker color={groupColor} />
          <span>{account.primaryGroupLabel}</span>
          <small className={styles.parentLabel}>({account.primaryGroupParentLabel})</small>
        </span>
      ) : null}
      {assetWarning ? <small className={styles.identity}>{assetWarning}</small> : null}
    </div>
  );
}
