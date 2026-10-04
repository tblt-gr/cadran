import type { PortfolioOverviewSource } from '@cadran/api-client';
import { useState, type ReactNode, type RefObject } from 'react';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { usePortfolioSummary } from '@/features/portfolio/usePortfolioOverview';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { PortfolioKpiGrid } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/PortfolioKpiGrid';
import { PortfolioKpiExplanationModal } from '@/features/portfolio/portfolio-account/kpi-explanation-modal/PortfolioKpiExplanationModal';
import { PerformanceCard } from '@/features/portfolio/portfolio-account/performance-card/PerformanceCard';
import type { KpiCode } from '@/features/portfolio/portfolio-account/overviewLabels';

/** Summary first, then the value history. The allocation lives in its own tab. */
export function PortfolioOverview({
  accountId,
  asOf,
  frozen = false,
  history,
  sourceReturnFocus,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  /** The value history card. */
  history: ReactNode;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const [explaining, setExplaining] = useState<KpiCode | null>(null);
  const summary = usePortfolioSummary(accountId, asOf, !frozen);
  const error = summary.error instanceof OperationRequestError ? summary.error : null;
  const valuation = summary.data?.valuation;
  const qualifiers =
    summary.data &&
    (valuation?.stale ||
      valuation?.completeness === 'PARTIAL' ||
      summary.data.newerPartialValuation) ? (
      <>
        {valuation?.stale ? (
          <StatusBadge tone="warning">{`Valorisation ancienne (${valuation.ageDays} jours)`}</StatusBadge>
        ) : null}
        {valuation?.completeness === 'PARTIAL' ? (
          <StatusBadge tone="warning">Valorisation partielle</StatusBadge>
        ) : null}
        <ValuationFallbackNotice
          selected={summary.data.valuation}
          newerPartial={summary.data.newerPartialValuation}
        />
      </>
    ) : null;
  const openSource = (source: PortfolioOverviewSource) => {
    const params = new URLSearchParams({
      asOf,
      tab: source.kind === 'OPERATION' ? 'operations' : 'valuations',
      [source.kind === 'OPERATION' ? 'operation' : 'valuation']: source.id,
    });
    window.history.pushState({}, '', `/portfolios/${accountId}?${params}`);
    window.dispatchEvent(new PopStateEvent('popstate'));
  };
  return (
    <>
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
          qualifiers={qualifiers}
          summary={summary.data ?? null}
          onExplain={(code) => {
            if (sourceReturnFocus)
              sourceReturnFocus.current =
                document.activeElement instanceof HTMLElement ? document.activeElement : null;
            setExplaining(code);
          }}
        />
      )}
      <PerformanceCard
        accountId={accountId}
        asOf={asOf}
        frozen={frozen}
        onSource={openSource}
        sourceReturnFocus={sourceReturnFocus}
      />
      {history}
      {explaining && summary.data ? (
        <PortfolioKpiExplanationModal
          code={explaining}
          explanation={summary.data.explanations[explaining]}
          onClose={() => setExplaining(null)}
          onSource={(source) => {
            setExplaining(null);
            openSource(source);
          }}
        />
      ) : null}
    </>
  );
}
