import { useTranslation } from 'react-i18next';
import { BalanceTable } from './BalanceTable';
import styles from './BalanceTab.module.css';

/** Mirrors BalanceTab element for element: title, button and headers are real, values pending. */
export function BalanceTabSkeleton({ loadingStatus }: { loadingStatus?: string }) {
  const { t } = useTranslation();

  return (
    <div className={styles.tab}>
      <div className={styles.toolbar}>
        <h2>{t('accounts.balanceTab.navLabel')}</h2>
        <button className="primary-action" disabled type="button">
          {t('accounts.balanceTab.record')}
        </button>
      </div>
      <BalanceTable items={null} loadingStatus={loadingStatus} />
    </div>
  );
}
