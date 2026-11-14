import type { AccountOverviewRow } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountNameCell.module.css';

interface AccountNameCellProps {
  account: AccountOverviewRow | null;
  /** The warning that the row is denominated in another asset than its group total. */
  assetWarning?: string | null;
  /** `institution · product · ••1234`, each part only when present. */
  identity?: string | null;
}

/**
 * The same name slot serves loaded rows and their skeletons. A primary, archived or closed
 * account is not marked by a badge: its state is read by screen readers and an inactive account
 * is muted.
 */
export function AccountNameCell({
  account,
  assetWarning = null,
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
        className={`${styles.accountLink} ${account && account.status !== 'ACTIVE' ? styles.inactive : ''}`.trim()}
        href={href}
        onClick={href ? (event) => handleClientNavigation(event, href) : undefined}
      >
        {account ? account.label : <SkeletonBlock className={styles.nameSkeleton} />}
      </a>
      <span className="sr-only">{status}</span>
      {account === null || identity ? (
        <small className={styles.identity}>
          {account ? identity : <SkeletonBlock className={styles.identitySkeleton} />}
        </small>
      ) : null}
      {assetWarning ? <small className={styles.identity}>{assetWarning}</small> : null}
    </div>
  );
}
