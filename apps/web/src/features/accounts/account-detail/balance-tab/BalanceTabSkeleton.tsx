import { BalanceTable } from './BalanceTable';
import styles from './BalanceTab.module.css';

/** Mirrors BalanceTab element for element: the headers are real, values pending. */
export function BalanceTabSkeleton({ loadingStatus }: { loadingStatus?: string }) {
  return (
    <div className={styles.tab}>
      <BalanceTable items={null} loadingStatus={loadingStatus} />
    </div>
  );
}
