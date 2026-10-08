import { useLifeInsuranceContract } from '@/features/portfolio/life-insurance/useLifeInsuranceContract';
import { useEffect, useState } from 'react';
import { usePortfolioSourceReturnFocus } from './usePortfolioSourceReturnFocus';
import { PortfolioAccountTabContent } from './PortfolioAccountTabContent';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { clearPortfolioDetailSelection } from './portfolioDetailLocation';
import { MonthEndValuationBanner } from '@/features/portfolio/month-end/month-end-valuation-banner/MonthEndValuationBanner';
import { ValueHistoryCard } from './value-history-card/ValueHistoryCard';
import { PortfolioOverview } from './portfolio-overview/PortfolioOverview';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountRequestError } from '@/features/accounts/accountError';
import { useAccountDetail } from '@/features/accounts/account-detail/useAccountDetail';
import { NotPortfolioState } from './operations/NotPortfolioState';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { PortfolioAccountPageSkeleton } from './PortfolioAccountPageSkeleton';
import { activeTab } from './portfolio-tabs/activeTab';
import { PortfolioTabs, type PortfolioTab } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';

/**
 * The portfolio page of one investment account. The tabs are Performance, Espèces, Positions, Opérations, Valorisations and Répartition;
 * every later portfolio capability adds its own tab to `PortfolioTabs`.
 */
export function PortfolioAccountPage({ accountId }: { accountId: string }) {
  const account = useAccountDetail(accountId);
  const insurance =
    account.data?.kind === 'INSURANCE_CONTRACT' && account.data?.valuationMode === 'PORTFOLIO';
  const contract = useLifeInsuranceContract(accountId, insurance);
  const contractRequired = Boolean(insurance && contract.isSuccess && !contract.data?.contract);
  const operationBlocked = Boolean(insurance && !contract.data?.contract);
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const [asOf, setAsOf] = useState(
    () => new URLSearchParams(window.location.search).get('asOf') || today,
  );
  const changeDate = (date: string) => {
    const url = clearPortfolioDetailSelection();
    url.searchParams.set('asOf', date);
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    window.dispatchEvent(new PopStateEvent('popstate'));
  };
  const [creating, setCreating] = useState(false);
  const [active, setActive] = useState<PortfolioTab>(() => activeTab());
  const sourceReturnFocus = usePortfolioSourceReturnFocus(active, asOf);

  useEffect(() => {
    const updateTab = () => {
      setActive(activeTab());
      setAsOf(new URLSearchParams(window.location.search).get('asOf') || today);
    };
    window.addEventListener('popstate', updateTab);
    return () => window.removeEventListener('popstate', updateTab);
  }, [today]);

  if (account.isPending) {
    return (
      <PortfolioAccountPageSkeleton
        accountId={accountId}
        asOf={asOf}
        today={today}
        status="Chargement du portefeuille…"
      />
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
  const eligible =
    (current.kind === 'PORTFOLIO' || current.kind === 'INSURANCE_CONTRACT') &&
    current.valuationMode === 'PORTFOLIO';

  function openCreate() {
    if (operationBlocked) return;
    if (active !== 'operations') {
      window.history.pushState({}, '', `/portfolios/${accountId}?tab=operations&asOf=${asOf}`);
      setActive('operations');
    }
    setCreating(true);
  }

  return (
    <div className={styles.page}>
      <PortfolioAccountHeader
        account={current}
        accountId={accountId}
        onCreate={openCreate}
        contractRequired={contractRequired}
        operationBlocked={operationBlocked}
      />
      {eligible ? <MonthEndValuationBanner accountId={accountId} asOf={asOf} /> : null}
      <BusinessDateField hint={null} label="Au" onChange={changeDate} today={today} value={asOf} />
      {eligible ? (
        <PortfolioOverview
          kind={current.kind}
          accountId={accountId}
          asOf={asOf}
          history={<ValueHistoryCard accountId={accountId} asOf={asOf} />}
          sourceReturnFocus={sourceReturnFocus}
        />
      ) : null}
      <PortfolioTabs accountId={accountId} active={active} asOf={asOf} kind={current.kind} />
      {eligible ? (
        <PortfolioAccountTabContent
          account={current}
          active={active}
          asOf={asOf}
          sourceReturnFocus={sourceReturnFocus}
          contractRequired={operationBlocked}
          onAsOfChange={changeDate}
          creating={creating}
          onCloseCreate={() => setCreating(false)}
          onCreate={openCreate}
        />
      ) : (
        <NotPortfolioState accountId={accountId} />
      )}
    </div>
  );
}
