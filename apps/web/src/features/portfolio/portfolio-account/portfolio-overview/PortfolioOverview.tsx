import { useState, type RefObject } from 'react';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import {
  usePortfolioSummary,
  usePortfolioAllocation,
  type AllocationBy,
} from '@/features/portfolio/usePortfolioOverview';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { PortfolioKpiGrid } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/PortfolioKpiGrid';
import { PortfolioKpiExplanationModal } from '@/features/portfolio/portfolio-account/kpi-explanation-modal/PortfolioKpiExplanationModal';
import { AllocationCard } from '@/features/portfolio/portfolio-account/allocation-card/AllocationCard';
import type { KpiCode } from '@/features/portfolio/portfolio-account/overviewLabels';
export function PortfolioOverview({
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
  const [by, setBy] = useState<AllocationBy>('INSTRUMENT');
  const [explaining, setExplaining] = useState<KpiCode | null>(null);
  const summary = usePortfolioSummary(accountId, asOf, !frozen);
  const allocation = usePortfolioAllocation(accountId, asOf, by, !frozen);
  const error = summary.error instanceof OperationRequestError ? summary.error : null;
  return (
    <>
      {summary.data?.valuation?.stale ? (
        <StatusBadge tone="warning">{`Valorisation ancienne (${summary.data.valuation.ageDays} jours)`}</StatusBadge>
      ) : null}
      {summary.data?.valuation?.completeness === 'PARTIAL' ? (
        <StatusBadge tone="warning">Valorisation partielle</StatusBadge>
      ) : null}
      {summary.isError ? (
        <OperationsState
          error
          title={
            error?.status === 404 || error?.status === 403
              ? 'Portefeuille introuvable ou non accessible'
              : error?.code === 'invalid_as_of'
                ? 'La date sélectionnée est invalide'
                : 'Les indicateurs sont indisponibles'
          }
        >
          <button className="secondary-action" type="button" onClick={() => void summary.refetch()}>
            Réessayer
          </button>
        </OperationsState>
      ) : (
        <PortfolioKpiGrid
          asOf={asOf}
          summary={summary.data ?? null}
          onExplain={(code) => {
            if (sourceReturnFocus)
              sourceReturnFocus.current =
                document.activeElement instanceof HTMLElement ? document.activeElement : null;
            setExplaining(code);
          }}
        />
      )}
      {allocation.isError ? (
        <OperationsState error title="La répartition est indisponible">
          <button
            className="secondary-action"
            type="button"
            onClick={() => void allocation.refetch()}
          >
            Réessayer
          </button>
        </OperationsState>
      ) : (
        <AllocationCard
          allocation={allocation.data ?? null}
          by={by}
          onByChange={setBy}
          disabled={frozen}
        />
      )}
      {explaining && summary.data ? (
        <PortfolioKpiExplanationModal
          code={explaining}
          explanation={summary.data.explanations[explaining]}
          onClose={() => setExplaining(null)}
          onSource={(source) => {
            setExplaining(null);
            const params = new URLSearchParams({
              asOf,
              tab: source.kind === 'OPERATION' ? 'operations' : 'valuations',
              [source.kind === 'OPERATION' ? 'operation' : 'valuation']: source.id,
            });
            window.history.pushState({}, '', `/portfolios/${accountId}?${params}`);
            window.dispatchEvent(new PopStateEvent('popstate'));
          }}
        />
      ) : null}
    </>
  );
}
