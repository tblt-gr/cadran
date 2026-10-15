import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import styles from './PositionLotsModal.module.css';

const lotHeaders = [
  'Acquis le',
  'Origine',
  'Quantité initiale',
  'Quantité restante',
  'Coût restant',
  'Coût unitaire',
];
const movementHeaders = ['Date', 'Opération', 'Δ quantité', 'Δ coût'];

/** Mirrors PositionLotsTables element for element: headings and headers are real, values pending. */
export function PositionLotsModalSkeleton() {
  return (
    <div aria-busy="true" className={styles.skeletonContents} data-testid="position-lots-skeleton">
      <SkeletonStatus>Chargement des lots…</SkeletonStatus>
      <h3>Lots ouverts</h3>
      <div className={styles.scroll}>
        <table aria-label="Lots ouverts" className={styles.table}>
          <thead>
            <tr>
              {lotHeaders.map((header) => (
                <th key={header} scope="col">
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <SkeletonTableRows columns={lotHeaders.length} rows={3} />
        </table>
      </div>
      <div className={styles.fifoTotal}>
        <span>Coût FIFO restant :</span>
        <SkeletonBlock className={styles.totalSkeleton} />
      </div>
      <h3>Mouvements</h3>
      <div className={styles.scroll}>
        <table aria-label="Mouvements des lots" className={styles.table}>
          <thead>
            <tr>
              {movementHeaders.map((header) => (
                <th key={header} scope="col">
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <SkeletonTableRows columns={movementHeaders.length} rows={3} />
        </table>
      </div>
    </div>
  );
}
