import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './PositionsTable.module.css';

/** Keeps the real table chrome while the derived positions are replayed. */
export function PositionsTableSkeleton({
  status = 'Chargement des positions…',
}: {
  status?: string;
}) {
  return (
    <section aria-busy="true" className={`card ${styles.panel}`} data-testid="positions-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table aria-label="Positions du portefeuille" className={styles.table}>
          <thead>
            <tr>
              <th className={styles.instrument} scope="col">
                Instrument
              </th>
              <th scope="col">Quantité</th>
              <th scope="col">Prix de revient moyen</th>
              <th scope="col">Coût total (moyen)</th>
              <th scope="col">Lots</th>
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={6} rows={6} />
        </table>
      </div>
    </section>
  );
}
