import type { Account } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './PortfolioAccountHeader.module.css';

/**
 * Institution and product line of a portfolio account, beside the page's own controls
 * (`children`), plus the two entry actions in the shell header: a new operation and a new
 * valuation. The account name is the shell title. Without `account` it is the loading state:
 * the actions stay real but disabled, only the account's own values are blocks.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
  onRecordValuation,
  contractRequired = false,
  operationBlocked = false,
  children,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
  onRecordValuation: () => void;
  contractRequired?: boolean;
  operationBlocked?: boolean;
  children?: ReactNode;
}) {
  const products = useProductOptions(todayInBrowser());
  const product = account?.productCode
    ? products.items.find((item) => item.code === account.productCode)
    : undefined;
  const subtitle = [account?.institution, product?.displayName].filter(Boolean).join(' · ');
  const instrumentsHref = '/portfolios/instruments';
  const writable =
    account !== undefined && account.status === 'ACTIVE' && valuedByPortfolio(account);
  const contractHref = `/portfolios/${accountId}?tab=contract`;

  return (
    <>
      <HeaderActions>
        <button
          aria-label="Nouvelle valorisation"
          className={`secondary-action ${styles.add}`}
          disabled={!writable}
          onClick={onRecordValuation}
          type="button"
        >
          <Icon name="balance" size={18} />
          <span className={styles.addLabel}>Nouvelle valorisation</span>
        </button>
        <button
          aria-label="Nouvelle opération"
          className={`primary-action ${styles.add}`}
          disabled={!writable || contractRequired || operationBlocked}
          onClick={onCreate}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>Nouvelle opération</span>
        </button>
        <a
          aria-label="Gérer les instruments"
          className="icon-button"
          href={instrumentsHref}
          onClick={(event) => handleClientNavigation(event, instrumentsHref)}
          title="Gérer les instruments"
        >
          <Icon name="chart-line" />
        </a>
      </HeaderActions>
      <div className={styles.meta}>
        <div className={styles.identity}>
          {!account ? (
            <SkeletonBlock className={styles.subtitleBlock} />
          ) : subtitle ? (
            <p className={styles.subtitle}>{subtitle}</p>
          ) : null}
          {contractRequired ? (
            <a href={contractHref} onClick={(event) => handleClientNavigation(event, contractHref)}>
              Renseignez d'abord le contrat
            </a>
          ) : null}
        </div>
        {children}
      </div>
    </>
  );
}
