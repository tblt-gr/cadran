import { RecordedAmounts } from './recorded-amounts/RecordedAmounts';
import type { RefObject } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useLifeInsuranceSummary } from '@/features/portfolio/life-insurance/useLifeInsuranceSummary';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { ContractFigures } from './contract-figures/ContractFigures';
import { LifeInsuranceAllocationCard } from './allocation-card/LifeInsuranceAllocationCard';
import { SupportsTable } from './supports-table/SupportsTable';
import { QualityIssues } from './quality-issues/QualityIssues';
import { LifeInsuranceOverviewSkeleton } from './LifeInsuranceOverviewSkeleton';
import styles from './LifeInsuranceOverview.module.css';
export function LifeInsuranceOverview({
  accountId,
  asOf,
  frozen = false,
  sourceReturnFocus,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const query = useLifeInsuranceSummary(accountId, asOf, !frozen);
  if (query.isPending) return <LifeInsuranceOverviewSkeleton />;
  const error = query.error instanceof OperationRequestError ? query.error : null;
  if (!query.data)
    return (
      <section className="card" role="alert">
        <h3>
          {error?.status === 404 || error?.status === 403
            ? 'Contrat introuvable ou non accessible'
            : error?.code === 'invalid_summary_date'
              ? 'La date sélectionnée est invalide'
              : 'La synthèse du contrat est indisponible'}
        </h3>
        <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
          Réessayer
        </button>
      </section>
    );
  const summary = query.data;
  const href = `/portfolios/${accountId}?${new URLSearchParams({ tab: 'contract', asOf })}`;
  return (
    <div className={styles.overview}>
      {query.isError ? (
        <p role="alert">
          L’actualisation de la synthèse a échoué. Les dernières données restent affichées.{' '}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </p>
      ) : null}
      {!summary.contract ? (
        <section className="card">
          <h3>Contrat</h3>
          <p>Renseignez le contrat d'assurance-vie pour enregistrer ses opérations.</p>
          <a
            className="secondary-action"
            href={href}
            onClick={(event) => handleClientNavigation(event, href)}
          >
            Renseigner le contrat
          </a>
        </section>
      ) : (
        <>
          <ContractFigures
            summary={summary}
            accountId={accountId}
            sourceReturnFocus={sourceReturnFocus}
          />
          <div className={styles.details}>
            <LifeInsuranceAllocationCard summary={summary} />
            <QualityIssues
              summary={summary}
              accountId={accountId}
              sourceReturnFocus={sourceReturnFocus}
            />
          </div>
          <SupportsTable summary={summary} />
          <RecordedAmounts
            summary={summary}
            accountId={accountId}
            sourceReturnFocus={sourceReturnFocus}
          />
        </>
      )}
    </div>
  );
}
