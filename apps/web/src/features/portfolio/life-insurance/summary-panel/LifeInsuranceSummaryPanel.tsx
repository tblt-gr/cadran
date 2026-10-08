import type { ReactNode, RefObject } from 'react';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useLifeInsuranceSummary } from '@/features/portfolio/life-insurance/useLifeInsuranceSummary';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import type { Summary } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './LifeInsuranceSummaryPanel.module.css';
export type InsuranceReportProps = {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
};
/** One dated contract read shared by tab-specific reports and the overview. */
export function LifeInsuranceSummaryPanel({
  accountId,
  asOf,
  frozen = false,
  title,
  children,
  announceLoading = true,
}: InsuranceReportProps & {
  title: string;
  children: (summary: Summary | null) => ReactNode;
  announceLoading?: boolean;
}) {
  const query = useLifeInsuranceSummary(accountId, asOf, !frozen);
  const error = query.error instanceof OperationRequestError ? query.error : null;
  const href = `/portfolios/${accountId}?${new URLSearchParams({ tab: 'contract', asOf })}`;
  return (
    <div className={styles.panel} aria-busy={query.isPending}>
      {query.isPending && announceLoading ? (
        <SkeletonStatus>Chargement des données du contrat…</SkeletonStatus>
      ) : null}
      {query.isError ? (
        <section className="card" role="alert">
          <h3>
            {error?.status === 404 || error?.status === 403
              ? 'Contrat introuvable ou non accessible'
              : error?.code === 'invalid_summary_date'
                ? 'La date sélectionnée est invalide'
                : title}
          </h3>
          {query.data ? <p>Les dernières données restent affichées.</p> : null}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </section>
      ) : null}
      {query.data && !query.data.contract ? (
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
      ) : query.isPending || query.data ? (
        children(query.data ?? null)
      ) : null}
    </div>
  );
}
