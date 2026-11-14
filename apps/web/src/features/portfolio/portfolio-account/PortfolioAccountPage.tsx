import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { useLifeInsuranceContract } from '@/features/portfolio/life-insurance/useLifeInsuranceContract';
import { useArrivalAction } from '@/features/accounts/account-actions-menu/useArrivalAction';
import { AccountTab } from './account-tab/AccountTab';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePortfolioSourceReturnFocus } from './usePortfolioSourceReturnFocus';
import { PortfolioAccountTabContent, type PortfolioCreation } from './PortfolioAccountTabContent';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { clearPortfolioDetailSelection } from './portfolioDetailLocation';
import { MonthEndValuationBanner } from '@/features/portfolio/month-end/month-end-valuation-banner/MonthEndValuationBanner';
import { ValueHistoryCard } from './value-history-card/ValueHistoryCard';
import { PortfolioOverview } from './portfolio-overview/PortfolioOverview';
import { AccountRequestError } from '@/features/accounts/accountError';
import { useAccountDetail } from '@/features/accounts/account-detail/useAccountDetail';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { NotPortfolioState } from './operations/NotPortfolioState';
import { PortfolioAccountHeader } from './portfolio-account-header/PortfolioAccountHeader';
import { PortfolioAccountPageSkeleton } from './PortfolioAccountPageSkeleton';
import { activeTab, tabForKind } from './portfolio-tabs/activeTab';
import { PortfolioTabs, type PortfolioTab } from './portfolio-tabs/PortfolioTabs';
import styles from './PortfolioAccountPage.module.css';
import { useBreadcrumbAccount } from '@/components/layout/breadcrumb/BreadcrumbContext';
import { usePortfolioTakeover } from '@/features/portfolio/takeover/usePortfolioTakeover';
import { PortfolioTakeoverModal } from '@/features/portfolio/takeover/PortfolioTakeoverModal';
import { TakeoverPrompt } from '@/features/portfolio/takeover/TakeoverPrompt';
import { formatCalendarDay } from '@/lib/decimal';
import { useAccountHeader } from '@/features/accounts/account-header/useAccountHeader';

/**
 * Account navigation leads to an identity and summary panel, then the value history.
 * Entry actions open their modal on the tab that lists the resulting record.
 */
export function PortfolioAccountPage({ accountId }: { accountId: string }) {
  const { t } = useTranslation();
  const account = useAccountDetail(accountId);
  const label = !account.isError && account.data?.id === accountId ? account.data.label : null;
  useBreadcrumbAccount(`/portfolios/${accountId}`, label, account.isPending);
  useAccountHeader(`/portfolios/${accountId}`, account.isError ? undefined : account.data);
  const insurance =
    account.data?.kind === 'INSURANCE_CONTRACT' && account.data?.valuationMode === 'PORTFOLIO';
  const contract = useLifeInsuranceContract(accountId, insurance);
  const contractRequired = Boolean(insurance && contract.isSuccess && !contract.data?.contract);
  const operationBlocked = Boolean(insurance && !contract.data?.contract);
  const takeoverCandidate =
    account.data?.id === accountId &&
    account.data.kind === 'PORTFOLIO' &&
    account.data.valuationMode === 'PORTFOLIO' &&
    account.data.status === 'ACTIVE' &&
    account.data.editable;
  const takeover = usePortfolioTakeover(accountId, takeoverCandidate);
  const takeoverState = takeover.data?.eligible ? takeover.data : null;
  const [takeoverNotice, setTakeoverNotice] = useState<string | null>(null);
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
  const [creating, setCreating] = useState<PortfolioCreation>(null);
  const [includeVoided, setIncludeVoided] = useState(false);
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

  function openOn(tab: PortfolioTab, creation: PortfolioCreation) {
    if (active !== tab) {
      window.history.pushState({}, '', `/portfolios/${accountId}?tab=${tab}&asOf=${asOf}`);
      setActive(tab);
    }
    setCreating(creation);
  }
  function openCreate() {
    if (operationBlocked) return;
    openOn('operations', 'operation');
  }
  useArrivalAction('operation', account.data?.id === accountId, () => {
    const loaded = account.data;
    if (loaded && loaded.status === 'ACTIVE' && loaded.editable && valuedByPortfolio(loaded))
      openCreate();
  });
  useArrivalAction('valuation', account.data?.id === accountId, () => {
    const loaded = account.data;
    if (loaded && loaded.status === 'ACTIVE' && loaded.editable && valuedByPortfolio(loaded))
      openOn('valuations', 'valuation');
  });

  if (account.isPending) {
    return (
      <PortfolioAccountPageSkeleton
        accountId={accountId}
        asOf={asOf}
        status="Chargement du portefeuille…"
      />
    );
  }

  if (account.isError) {
    const status = account.error instanceof AccountRequestError ? account.error.status : 0;
    if (status === 404 || status === 403) return <PlaceholderPage />;
    return (
      <section className={`card ${styles.routeState}`} role="alert">
        <h2>Le portefeuille est indisponible</h2>
        <button className="secondary-action" onClick={() => void account.refetch()} type="button">
          Réessayer
        </button>
      </section>
    );
  }

  const current = account.data;
  const eligible = valuedByPortfolio(current);
  const shown = tabForKind(active, current.kind);

  return (
    <div className={styles.page}>
      <PortfolioTabs accountId={accountId} active={shown} asOf={asOf} kind={current.kind} />
      <PortfolioAccountHeader
        account={current}
        accountId={accountId}
        onCreate={openCreate}
        onRecordValuation={() => openOn('valuations', 'valuation')}
        onTakeover={takeoverState ? () => setCreating('takeover') : undefined}
        contractRequired={contractRequired}
        operationBlocked={operationBlocked}
        valuationPrimary={shown === 'valuations'}
        voided={
          shown === 'operations'
            ? { active: includeVoided, onToggle: () => setIncludeVoided((value) => !value) }
            : undefined
        }
      />
      {current.status !== 'ACTIVE' ? (
        <p className={styles.notice} role="status">
          {t(`accounts.detail.actions.unavailable.${current.status}`)}
        </p>
      ) : null}
      {takeoverNotice ? (
        <p className={styles.notice} role="status">
          {takeoverNotice}
        </p>
      ) : null}
      {takeoverState && shown === 'account' ? (
        <TakeoverPrompt onTakeover={() => setCreating('takeover')} />
      ) : null}
      {takeoverState && creating === 'takeover' ? (
        <PortfolioTakeoverModal
          account={current}
          defaultDate={takeoverState.defaultDate}
          onClose={() => setCreating(null)}
          onSaved={(takenOverOn) => {
            setCreating(null);
            setTakeoverNotice(`Positions reprises au ${formatCalendarDay(takenOverOn, 'fr')}.`);
          }}
        />
      ) : null}
      {eligible && shown === 'account' ? (
        <AccountTab account={current} today={today}>
          <PortfolioOverview
            embedded
            kind={current.kind}
            accountId={accountId}
            asOf={asOf}
            history={null}
            sourceReturnFocus={sourceReturnFocus}
          />
        </AccountTab>
      ) : null}
      {eligible && shown === 'account' ? (
        <MonthEndValuationBanner accountId={accountId} asOf={asOf} />
      ) : null}
      {eligible && shown === 'account' ? (
        <ValueHistoryCard accountId={accountId} asOf={asOf} />
      ) : null}
      {eligible && shown !== 'account' ? (
        <PortfolioAccountTabContent
          account={current}
          active={shown}
          asOf={asOf}
          today={today}
          sourceReturnFocus={sourceReturnFocus}
          contractRequired={operationBlocked}
          includeVoided={includeVoided}
          onAsOfChange={changeDate}
          creating={creating}
          onCloseCreate={() => setCreating(null)}
          onCreate={openCreate}
          onTakeover={takeoverState ? () => setCreating('takeover') : undefined}
        />
      ) : !eligible ? (
        <NotPortfolioState accountId={accountId} />
      ) : null}
    </div>
  );
}
