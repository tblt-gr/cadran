import type { Account } from '@cadran/api-client';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './PortfolioAccountHeader.module.css';

/**
 * Identity and primary action of a portfolio account. Without `account` it is the
 * loading state: the static chrome stays real, only the account's own values are blocks.
 */
export function PortfolioAccountHeader({
  account,
  accountId,
  onCreate,
}: {
  account?: Account;
  accountId: string;
  onCreate: () => void;
}) {
  const products = useProductOptions(todayInBrowser());
  const product = account?.productCode
    ? products.items.find((item) => item.code === account.productCode)
    : undefined;
  const subtitle = [account?.institution, product?.displayName].filter(Boolean).join(' · ');
  const href = `/accounts/${accountId}`;
  const instrumentsHref = '/portfolios/instruments';

  return (
    <section aria-labelledby="portfolio-account-title" className={styles.intro}>
      <div>
        <h2 id="portfolio-account-title">
          {account ? account.label : <SkeletonBlock className={styles.title} />}
        </h2>
        {account ? (
          subtitle ? (
            <p className={styles.subtitle}>{subtitle}</p>
          ) : null
        ) : (
          <SkeletonBlock className={styles.subtitleBlock} />
        )}
      </div>
      <div className={styles.actions}>
        <a
          className="secondary-action"
          href={href}
          onClick={(event) => handleClientNavigation(event, href)}
        >
          Voir le compte
        </a>
        <a
          className="secondary-action"
          href={instrumentsHref}
          onClick={(event) => handleClientNavigation(event, instrumentsHref)}
        >
          Gérer les instruments
        </a>
        <button
          className="primary-action"
          disabled={
            !account ||
            account.status !== 'ACTIVE' ||
            account.kind !== 'PORTFOLIO' ||
            account.valuationMode !== 'PORTFOLIO'
          }
          onClick={onCreate}
          type="button"
        >
          Nouvelle opération
        </button>
      </div>
    </section>
  );
}
