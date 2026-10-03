import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { OPERATION_COLUMNS } from './operations-table/operationColumns';
import styles from './operations-table/OperationsTable.module.css';

/** The table's real header and caption with value cells held by blocks. */
export function OperationsTableSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={`card ${styles.panel}`} data-testid="operations-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <thead>
            <tr>
              {OPERATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th aria-hidden="true" />
            </tr>
          </thead>
          <SkeletonTableRows columns={OPERATION_COLUMNS.length + 1} rows={8} />
        </table>
      </div>
    </section>
  );
}
