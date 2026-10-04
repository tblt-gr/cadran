import { ValuationsTableSkeleton } from './ValuationsTableSkeleton';
import { ValuationsToolbar } from './ValuationsToolbar';
import styles from './ValuationsTab.module.css';

/** Cold-load layout of the Valorisations tab: the real chrome, every value pending. */
export function ValuationsTabSkeleton({ status }: { status: string }) {
  return (
    <div className={styles.tab}>
      <ValuationsToolbar
        disabled
        dueMonths={null}
        onCreate={() => undefined}
        onMonthEnd={() => undefined}
      />
      <ValuationsTableSkeleton status={status} />
    </div>
  );
}
