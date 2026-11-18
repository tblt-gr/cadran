import { ValuationDayList } from './valuation-day-list/ValuationDayList';
import styles from './ValuationsTab.module.css';

/** Cold-load layout of the Valorisations tab: the real chrome, every value pending. */
export function ValuationsTabSkeleton({ status }: { status: string }) {
  return (
    <div className={styles.tab}>
      <ValuationDayList
        loadingLabel={status}
        onDetail={() => undefined}
        onReplace={() => undefined}
        onWithdraw={() => undefined}
        valuations={null}
      />
    </div>
  );
}
