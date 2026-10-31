import { ContractReportingPanel } from './ContractReportingPanel';
import type { RefObject } from 'react';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useState } from 'react';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { useLifeInsuranceContract } from '@/features/portfolio/life-insurance/useLifeInsuranceContract';
import { ContractCard } from '@/features/portfolio/life-insurance/contract-card/ContractCard';
import { FeeTermsCard } from '@/features/portfolio/life-insurance/fee-terms-card/FeeTermsCard';
import { EuroFundRatesCard } from '@/features/portfolio/life-insurance/euro-fund-rates-card/EuroFundRatesCard';
import { ContractModal } from '@/features/portfolio/life-insurance/contract-modal/ContractModal';
import { FeeTermsModal } from '@/features/portfolio/life-insurance/fee-terms-modal/FeeTermsModal';
import { EuroFundRateModal } from '@/features/portfolio/life-insurance/euro-fund-rate-modal/EuroFundRateModal';
import { RemoveRecordModal } from '@/features/portfolio/life-insurance/remove-record-modal/RemoveRecordModal';
import { ContractTabSkeleton } from './ContractTabSkeleton';
import styles from './ContractTab.module.css';
export function ContractTab({
  accountId,
  assetCode,
  writable = true,
  asOf,
  sourceReturnFocus,
}: {
  accountId: string;
  assetCode: string;
  writable?: boolean;
  asOf?: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const query = useLifeInsuranceContract(accountId);
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const [modal, setModal] = useState<'contract' | 'fee' | 'rate' | null>(null);
  const [remove, setRemove] = useState<{ id: string; kind: 'fee' | 'rate' } | null>(null);
  const close = () => setModal(null);
  if (query.isPending) return <ContractTabSkeleton />;
  if (query.isError && !query.data)
    return (
      <section className={`card ${styles.state}`} role="alert">
        <h3>Le contrat est indisponible</h3>
        <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
          Réessayer
        </button>
      </section>
    );
  const data = query.data!;
  return (
    <div className={styles.tab}>
      {query.isError ? (
        <p role="alert">
          L’actualisation du contrat a échoué. Les dernières données restent affichées.{' '}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </p>
      ) : null}
      {data.contract?.status === 'CLOSED' && data.contract.closedOn ? (
        <p role="status">
          Contrat soldé le {formatCalendarNumericDay(data.contract.closedOn)}. Vous pouvez clôturer
          le compte depuis la{' '}
          <a href="/accounts" onClick={(event) => handleClientNavigation(event, '/accounts')}>
            liste des comptes
          </a>
          .
        </p>
      ) : null}
      {data.contract ? (
        <>
          <ContractCard
            contract={data.contract}
            writable={writable}
            onEdit={() => setModal('contract')}
          />
          <ContractReportingPanel
            accountId={accountId}
            asOf={asOf ?? today}
            sourceReturnFocus={sourceReturnFocus}
          />
          <FeeTermsCard
            items={data.feeTerms}
            writable={writable}
            onAdd={() => setModal('fee')}
            onRemove={(id) => setRemove({ id, kind: 'fee' })}
          />
          <EuroFundRatesCard
            items={data.euroFundRates}
            euroFunds={data.euroFunds}
            lastClosedYear={Number(today.slice(0, 4)) - 1}
            writable={writable}
            onAdd={() => setModal('rate')}
            onRemove={(id) => setRemove({ id, kind: 'rate' })}
          />
        </>
      ) : (
        <section className={`card ${styles.state}`}>
          <h3>Contrat</h3>
          <p>Renseignez le contrat d'assurance-vie pour enregistrer ses opérations.</p>
          <button
            className="primary-action"
            type="button"
            disabled={!writable}
            onClick={() => setModal('contract')}
          >
            Renseigner le contrat
          </button>
        </section>
      )}
      {modal === 'contract' ? (
        <ContractModal
          accountId={accountId}
          contract={data.contract ?? undefined}
          today={today}
          onClose={close}
        />
      ) : null}
      {modal === 'fee' && data.contract ? (
        <FeeTermsModal
          accountId={accountId}
          assetCode={assetCode}
          subscribedOn={data.contract.subscribedOn}
          onClose={close}
        />
      ) : null}
      {modal === 'rate' && data.contract ? (
        <EuroFundRateModal
          accountId={accountId}
          assetCode={assetCode}
          subscribedOn={data.contract.subscribedOn}
          today={today}
          onClose={close}
        />
      ) : null}
      {remove ? (
        <RemoveRecordModal accountId={accountId} {...remove} onClose={() => setRemove(null)} />
      ) : null}
    </div>
  );
}
