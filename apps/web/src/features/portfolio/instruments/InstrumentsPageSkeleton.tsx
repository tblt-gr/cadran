import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './instrument-table/InstrumentTable.module.css';

export function InstrumentsPageSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={`card ${styles.panel}`} data-testid="instruments-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <thead>
            <tr>
              {['Nom', 'ISIN', 'Ticker', 'Type', 'Classe', 'Région', 'Devise', 'Statut', ''].map(
                (label, index) => (
                  <th aria-hidden={index === 8 ? 'true' : undefined} key={label}>
                    {label}
                  </th>
                ),
              )}
            </tr>
          </thead>
          <SkeletonTableRows columns={9} rows={8} />
        </table>
      </div>
    </section>
  );
}
