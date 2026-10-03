import { useState } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountRequestError } from '@/features/accounts/accountError';
import { useAccountDetail } from '@/features/accounts/account-detail/useAccountDetail';
import { NotPortfolioState } from './operations/NotPortfolioState';
import { OperationsTab } from './operations/OperationsTab';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { PortfolioAccountPageSkeleton } from './PortfolioAccountPageSkeleton';
import { PortfolioTabs } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';

/**
 * The portfolio page of one investment account. This ticket ships the Opérations tab;
 * every later portfolio capability adds its own tab to `PortfolioTabs`.
 */
export function PortfolioAccountPage({ accountId }: { accountId: string }) {
  const account = useAccountDetail(accountId);
  const [creating, setCreating] = useState(false);

  if (account.isPending) {
    return (
      <PortfolioAccountPageSkeleton accountId={accountId} status="Chargement du portefeuille…" />
    );
  }

  if (account.isError) {
    const status = account.error instanceof AccountRequestError ? account.error.status : 0;
    const notFound = status === 404 || status === 403;
    return (
      <section className={`card ${styles.routeState}`} role="alert">
        <h2>{notFound ? 'Compte introuvable' : 'Le portefeuille est indisponible'}</h2>
        {notFound ? (
          <p>Ce compte n’existe pas ou n’est pas accessible dans cet espace.</p>
        ) : (
          <button className="secondary-action" onClick={() => void account.refetch()} type="button">
            Réessayer
          </button>
        )}
        <a
          className="secondary-action"
          href="/accounts"
          onClick={(event) => handleClientNavigation(event, '/accounts')}
        >
          Retour aux comptes
        </a>
      </section>
    );
  }

  const current = account.data;
  const eligible = current.kind === 'PORTFOLIO' && current.valuationMode === 'PORTFOLIO';

  return (
    <div className={styles.page}>
      <PortfolioAccountHeader
        account={current}
        accountId={accountId}
        onCreate={() => setCreating(true)}
      />
      <PortfolioTabs accountId={accountId} active="operations" />
      {eligible ? (
        <OperationsTab
          account={current}
          creating={creating}
          onCloseCreate={() => setCreating(false)}
          onCreate={() => setCreating(true)}
        />
      ) : (
        <NotPortfolioState accountId={accountId} />
      )}
    </div>
  );
}
