import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import { PortfolioCardCurve } from './PortfolioCardCurve';
import styles from './PortfolioAccountCard.module.css';

/** Title, type, latest total and curve; the title is the link, so the curve stays hoverable and focusable. */
export function PortfolioAccountCard({
  account,
}: {
  account: PortfolioOverviewAccountCard | null;
}) {
  if (!account) {
    return (
      <article aria-hidden="true" className={`card ${styles.card}`}>
        <SkeletonBlock />
        <SkeletonBlock />
        <SkeletonBlock />
        <SkeletonBlock className={styles.curve} />
      </article>
    );
  }
  const href = `/portfolios/${account.accountId}`;
  const total = account.latestValuation?.totalValue;

  return (
    <article className={`card ${styles.card}`}>
      <h3>
        <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
          {account.label}
        </a>
      </h3>
      <p>{account.productCode ?? 'Compte d’investissement'}</p>
      <p className={styles.value}>
        {total == null ? 'Non valorisé' : formatAmount(total, account.assetCode, 'fr')}
      </p>
      <PortfolioCardCurve accountId={account.accountId} assetCode={account.assetCode} />
    </article>
  );
}
