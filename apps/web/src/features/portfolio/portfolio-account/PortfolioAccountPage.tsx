import { useEffect, useState } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountRequestError } from '@/features/accounts/accountError';
import { useAccountDetail } from '@/features/accounts/account-detail/useAccountDetail';
import { CashTab } from '@/features/portfolio/cash/CashTab';
import { ValuationsTab } from '@/features/portfolio/valuations/ValuationsTab';
import { NotPortfolioState } from './operations/NotPortfolioState';
import { OperationsTab } from './operations/OperationsTab';
import { PositionsTab } from './positions/PositionsTab';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { PortfolioAccountPageSkeleton } from './PortfolioAccountPageSkeleton';
import { activeTab } from './portfolio-tabs/activeTab';
import { PortfolioTabs, type PortfolioTab } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';

/**
 * The portfolio page of one investment account. The tabs are Positions, Opérations, Espèces and Valorisations;
 * every later portfolio capability adds its own tab to `PortfolioTabs`.
 */
export function PortfolioAccountPage({ accountId }: { accountId: string }) {
  const account = useAccountDetail(accountId);
  const [creating, setCreating] = useState(false);
  const [active, setActive] = useState<PortfolioTab>(() => activeTab());

  useEffect(() => {
    const updateTab = () => setActive(activeTab());
    window.addEventListener('popstate', updateTab);
    return () => window.removeEventListener('popstate', updateTab);
  }, []);

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

  function openCreate() {
    if (active !== 'operations') {
      window.history.pushState({}, '', `/portfolios/${accountId}?tab=operations`);
      setActive('operations');
    }
    setCreating(true);
  }

  return (
    <div className={styles.page}>
      <PortfolioAccountHeader account={current} accountId={accountId} onCreate={openCreate} />
      <PortfolioTabs accountId={accountId} active={active} />
      {eligible ? (
        active === 'positions' ? (
          <PositionsTab accountId={accountId} />
        ) : active === 'cash' ? (
          <CashTab accountId={accountId} />
        ) : active === 'valuations' ? (
          <ValuationsTab account={current} />
        ) : (
          <OperationsTab
            account={current}
            creating={creating}
            onCloseCreate={() => setCreating(false)}
            onCreate={openCreate}
          />
        )
      ) : (
        <NotPortfolioState accountId={accountId} />
      )}
    </div>
  );
}
