import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { VALUATION_COLUMNS } from './valuations-table/valuationColumns';
import styles from './valuations-table/ValuationsTable.module.css';

/** Mirrors ValuationsTable element for element: header and caption are real, values pending. */
export function ValuationsTableSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="valuations-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Valorisations du portefeuille</caption>
          <thead>
            <tr>
              {VALUATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={VALUATION_COLUMNS.length + 1} rows={6} />
        </table>
      </div>
    </div>
  );
}
