import { Fragment, type ReactNode } from 'react';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './DatedList.module.css';

interface DatedRowListProps<T> {
  /** Items, or null while loading: the list then mirrors its loaded markup with placeholder rows. */
  items: readonly T[] | null;
  getKey: (item: T) => string;
  /** Accessible name of the list. */
  label: string;
  /** Announced once while loading, unless `announce` is false. */
  loadingLabel: string;
  announce?: boolean;
  /** `item` is null for a loading placeholder, which must keep the final markup. */
  renderRow: (item: T | null) => ReactNode;
  /** Number of placeholder rows while loading. */
  skeletonRows?: number;
  skeletonTestId?: string;
}

/**
 * The rows of a dated list without day headings: one card, one list. For data that has no
 * booking day to group by, with the same row anatomy as `DatedList`. Not windowed, so it suits
 * lists that stay short.
 */
export function DatedRowList<T>({
  announce = true,
  getKey,
  items,
  label,
  loadingLabel,
  renderRow,
  skeletonRows = 4,
  skeletonTestId = 'dated-row-list-skeleton',
}: DatedRowListProps<T>) {
  const loading = items === null;

  return (
    <div
      aria-busy={loading || undefined}
      className={`card ${styles.dayCard}`}
      data-testid={loading ? skeletonTestId : undefined}
    >
      {loading && announce ? <SkeletonStatus>{loadingLabel}</SkeletonStatus> : null}
      <ul aria-label={label} className={styles.rows}>
        {loading
          ? Array.from({ length: skeletonRows }, (_, index) => (
              <Fragment key={index}>{renderRow(null)}</Fragment>
            ))
          : items.map((item) => <Fragment key={getKey(item)}>{renderRow(item)}</Fragment>)}
      </ul>
    </div>
  );
}
