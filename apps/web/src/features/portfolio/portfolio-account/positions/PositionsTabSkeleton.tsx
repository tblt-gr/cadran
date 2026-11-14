import { PositionsTableSkeleton } from './positions-table/PositionsTableSkeleton';
import styles from './PositionsTab.module.css';

/** Faithful cold-load layout used before the account or positions response is available. */
export function PositionsTabSkeleton({
  status = 'Chargement des positions…',
}: {
  status?: string;
}) {
  return (
    <div className={styles.tab}>
      <PositionsTableSkeleton status={status} />
    </div>
  );
}
