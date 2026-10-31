import { ValuationsTableSkeleton } from './ValuationsTableSkeleton';
import styles from './ValuationsTab.module.css';

/** Cold-load layout of the Valorisations tab: the real chrome, every value pending. */
export function ValuationsTabSkeleton({ status }: { status: string }) {
  return (
    <div className={styles.tab}>
      <ValuationsTableSkeleton status={status} />
    </div>
  );
}
