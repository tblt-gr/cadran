import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { HoldingsView } from './HoldingsView';
import { DEFAULT_HOLDINGS_SORT, type HoldingsSort } from './useHoldings';
import styles from './InvestmentsPage.module.css';

/** Mirrors the loaded positions element for element: the view itself, with no data. */
export function InvestmentsPageSkeleton({
  status,
  sort = DEFAULT_HOLDINGS_SORT,
}: {
  status: string;
  sort?: HoldingsSort;
}) {
  return (
    <div aria-busy="true" className={styles.content} data-testid="investments-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <HoldingsView holdings={null} onSortChange={() => undefined} sort={sort} />
    </div>
  );
}
