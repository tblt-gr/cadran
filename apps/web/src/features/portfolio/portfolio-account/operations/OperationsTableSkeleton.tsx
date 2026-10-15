import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { OPERATION_COLUMNS } from './operations-table/operationColumns';
import styles from './operations-table/OperationsTable.module.css';

/** Mirrors OperationsTable element for element: header and caption are real, values pending. */
export function OperationsTableSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="operations-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Opérations du portefeuille</caption>
          <thead>
            <tr>
              {OPERATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={OPERATION_COLUMNS.length + 1} rows={8} />
        </table>
      </div>
    </div>
  );
}
