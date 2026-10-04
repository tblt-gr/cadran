import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import { UnrealizedGain } from '@/features/portfolio/portfolios/unrealized-gain/UnrealizedGain';
import { PortfolioCardCurve } from './PortfolioCardCurve';
import styles from './PortfolioAccountCard.module.css';

/** Name, institution, latest total, unrealized gain and curve; the name is the link, so the curve stays hoverable and focusable. */
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
  const gain = account.unrealizedGain;

  return (
    <article className={`card ${styles.card}`}>
      <h3>
        <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
          {account.label}
        </a>
      </h3>
      <p>{account.institution ?? account.productCode ?? 'Compte d’investissement'}</p>
      <p className={styles.value}>
        {total == null ? (
          <EmptyValue label="Non valorisé" />
        ) : (
          formatAmount(total, account.assetCode, 'fr')
        )}
      </p>
      <p className={styles.gain}>
        <UnrealizedGain
          assetCode={account.assetCode}
          performance={gain.performance}
          reason={gain.reason}
          value={gain.value}
        />
      </p>
      <PortfolioCardCurve accountId={account.accountId} assetCode={account.assetCode} />
    </article>
  );
}
