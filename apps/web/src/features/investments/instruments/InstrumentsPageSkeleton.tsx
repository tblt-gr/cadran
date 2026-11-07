import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './instrument-table/InstrumentTable.module.css';

/** Mirrors InstrumentTable element for element: header and caption are real, rows pending. */
export function InstrumentsPageSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="instruments-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Instruments</caption>
          <thead>
            <tr>
              {['Nom', 'ISIN', 'Ticker', 'Type', 'Classe', 'Région', 'Devise', 'Statut', ''].map(
                (text) => (
                  <th key={text} scope="col">
                    {text}
                  </th>
                ),
              )}
            </tr>
          </thead>
          <SkeletonTableRows columns={9} rows={8} />
        </table>
      </div>
    </div>
  );
}
