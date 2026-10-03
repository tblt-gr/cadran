import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { VALUATION_COLUMNS } from './valuations-table/valuationColumns';
import styles from './valuations-table/ValuationsTable.module.css';

/** The table's real header with value cells held by blocks. */
export function ValuationsTableSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={`card ${styles.panel}`} data-testid="valuations-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <thead>
            <tr>
              {VALUATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th aria-hidden="true" />
            </tr>
          </thead>
          <SkeletonTableRows columns={VALUATION_COLUMNS.length + 1} rows={6} />
        </table>
      </div>
    </section>
  );
}
