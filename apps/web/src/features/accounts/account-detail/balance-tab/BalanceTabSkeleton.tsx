import { useTranslation } from 'react-i18next';
import { BalanceDayList } from '@/features/accounts/balance-day-list/BalanceDayList';
import styles from './BalanceTab.module.css';

/** Mirrors BalanceTab element for element: the day list with its values pending. */
export function BalanceTabSkeleton({ loadingStatus }: { loadingStatus?: string }) {
  const { t } = useTranslation();
  return (
    <div className={styles.tab}>
      <BalanceDayList
        announce={loadingStatus !== undefined}
        items={null}
        label={t('accounts.balanceTab.caption')}
        loadingLabel={loadingStatus ?? ''}
      />
    </div>
  );
}
