import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import styles from './PortfolioAccountCard.module.css';
export function PortfolioAccountCard({
  account,
}: {
  account: PortfolioOverviewAccountCard | null;
}) {
  const href = account ? `/portfolios/${account.accountId}` : undefined;
  const content = (
    <>
      <h3>{account ? account.label : <SkeletonBlock />}</h3>
      {account ? (
        <p>{[account.institution, account.productCode].filter(Boolean).join(' · ')}</p>
      ) : (
        <SkeletonBlock />
      )}
      <dl>
        <div>
          <dt>Valeur totale</dt>
          <dd>
            {account ? (
              account.latestValuation?.totalValue == null ? (
                'Non valorisé'
              ) : (
                formatAmount(account.latestValuation.totalValue, account.assetCode, 'fr')
              )
            ) : (
              <SkeletonBlock />
            )}
          </dd>
        </div>
        <div>
          <dt>Espèces</dt>
          <dd>
            {account ? (
              formatAmount(account.currentCash, account.assetCode, 'fr')
            ) : (
              <SkeletonBlock />
            )}
          </dd>
        </div>
      </dl>
      {account?.latestValuation ? (
        <>
          <p>au {account.latestValuation.valuedOn}</p>
          <StatusBadge
            tone={
              account.latestValuation.stale || account.latestValuation.completeness === 'PARTIAL'
                ? 'warning'
                : 'positive'
            }
          >
            {account.latestValuation.completeness === 'PARTIAL'
              ? 'Valorisation partielle'
              : account.latestValuation.stale
                ? `Valorisation ancienne (${account.latestValuation.ageDays} jours)`
                : 'Valorisation complète'}
          </StatusBadge>
        </>
      ) : null}
      {account ? (
        <ValuationFallbackNotice
          selected={account.latestValuation}
          newerPartial={account.newerPartialValuation}
        />
      ) : null}
      {account ? <p>{account.positionCount} positions</p> : <SkeletonBlock />}
    </>
  );
  return account ? (
    <a
      aria-label={`Ouvrir le portefeuille ${account.label}`}
      className={`card ${styles.card}`}
      href={href}
      onClick={(event) => handleClientNavigation(event, href!)}
    >
      {content}
    </a>
  ) : (
    <article aria-hidden="true" className={`card ${styles.card}`}>
      {content}
    </article>
  );
}
