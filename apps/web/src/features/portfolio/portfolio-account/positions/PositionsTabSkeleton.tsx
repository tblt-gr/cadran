import { PositionsList } from './positions-list/PositionsList';
import styles from './PositionsTab.module.css';

/** Faithful cold-load layout used before the account or positions response is available. */
export function PositionsTabSkeleton({
  status = 'Chargement des positions…',
}: {
  status?: string;
}) {
  return (
    <div className={styles.tab}>
      <PositionsList items={null} loadingLabel={status} onOpen={() => {}} onReorder={() => {}} />
    </div>
  );
}
