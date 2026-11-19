import type { PortfolioOverviewSource } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
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
      <Modal
        intent="detail"
        close={onClose}
        title={t('portfolio.performance.monthExplanation.title')}
      >
        <p>{t('portfolio.performance.monthExplanation.unavailable')}</p>
        <button
          className="secondary-action"
          onClick={() => void performance.refetch()}
          type="button"
        >
          {t('actions.retry')}
        </button>
      </Modal>
    );
  }
  return (
    <p className="sr-only" role="status">
      {t('portfolio.performance.monthExplanation.loading')}
    </p>
  );
}
