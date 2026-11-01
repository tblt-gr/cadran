import { useTranslation } from 'react-i18next';
import { usePortfolios } from '@/features/portfolio/usePortfolioOverview';
import styles from './PortfolioPerformanceNotice.module.css';

/**
 * Follows the portfolios read the account rows start (same query key, never started from here):
 * rows state the failure cell by cell, this notice is the one place that offers the retry.
 */
export function PortfolioPerformanceNotice() {
  const { t } = useTranslation();
  const portfolios = usePortfolios(false);

  if (!portfolios.isError) return null;

  return (
    <p className={styles.notice} role="alert">
      {t('accounts.performance.notice')}{' '}
      <button className="secondary-action" onClick={() => void portfolios.refetch()} type="button">
        {t('accounts.performance.retry')}
      </button>
    </p>
  );
}
