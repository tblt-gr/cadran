import type { PortfolioOverviewSource } from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';

/** Fetches the `MONTH` response of one month on demand, then opens the explanation of one figure. */
export function MonthExplanation({
  accountId,
  month,
  figure,
  onClose,
  onSource,
}: {
  accountId: string;
  month: string;
  figure: FigureKey;
  onClose: () => void;
  onSource: (source: PortfolioOverviewSource) => void;
}) {
  const performance = usePortfolioPerformance(accountId, 'MONTH', month);
  if (performance.data) {
    return (
      <PerformanceExplanationModal
        figure={figure}
        onClose={onClose}
        onSource={onSource}
        performance={performance.data}
      />
    );
  }
  if (performance.isError) {
    return (
      <Modal close={onClose} title="Explication indisponible">
        <p>L'explication de ce mois est indisponible.</p>
        <button
          className="secondary-action"
          onClick={() => void performance.refetch()}
          type="button"
        >
          Réessayer
        </button>
      </Modal>
    );
  }
  return (
    <p className="sr-only" role="status">
      Chargement de l'explication…
    </p>
  );
}
