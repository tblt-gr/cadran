import type { Account } from '@cadran/api-client';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './PortfolioAccountHeader.module.css';

/**
 * Institution and product line of a portfolio account, plus its page actions in
 * the shell header. The account name already lives in the shell header and the
 * breadcrumb. Without `account` it is the loading state: the actions stay real
 * but disabled, only the account's own values are blocks.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
  contractRequired = false,
  operationBlocked = false,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
  contractRequired?: boolean;
  operationBlocked?: boolean;
}) {
  const products = useProductOptions(todayInBrowser());
  const product = account?.productCode
    ? products.items.find((item) => item.code === account.productCode)
    : undefined;
  const subtitle = [account?.institution, product?.displayName].filter(Boolean).join(' · ');
  const instrumentsHref = '/portfolios/instruments';

  return (
    <>
      <HeaderActions>
        <a
          aria-label="Gérer les instruments"
          className="icon-button"
          href={instrumentsHref}
          onClick={(event) => handleClientNavigation(event, instrumentsHref)}
          title="Gérer les instruments"
        >
          <Icon name="catalog" />
        </a>
        <button
          aria-label="Nouvelle opération"
          className={`primary-action ${styles.add}`}
          disabled={
            !account ||
            account.status !== 'ACTIVE' ||
            (account.kind !== 'PORTFOLIO' && account.kind !== 'INSURANCE_CONTRACT') ||
            contractRequired ||
            operationBlocked ||
            account.valuationMode !== 'PORTFOLIO'
          }
          onClick={onCreate}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>Nouvelle opération</span>
        </button>
      </HeaderActions>
      {account ? (
        subtitle || contractRequired ? (
          <div className={styles.meta}>
            {subtitle ? <p className={styles.subtitle}>{subtitle}</p> : null}
            {contractRequired ? (
              <a
                href={`/portfolios/${accountId}?tab=contract`}
                onClick={(event) =>
                  handleClientNavigation(event, `/portfolios/${accountId}?tab=contract`)
                }
              >
                Renseignez d'abord le contrat
              </a>
            ) : null}
          </div>
        ) : null
      ) : (
        <SkeletonBlock className={styles.subtitleBlock} />
      )}
    </>
  );
}
