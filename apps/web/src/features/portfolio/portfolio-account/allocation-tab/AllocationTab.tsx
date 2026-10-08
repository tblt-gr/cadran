import { useState } from 'react';
import {
  usePortfolioAllocation,
  usePortfolioSummary,
  type AllocationBy,
} from '@/features/portfolio/usePortfolioOverview';
import { AllocationCard } from '@/features/portfolio/portfolio-account/allocation-card/AllocationCard';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import styles from './AllocationTab.module.css';

/** The Allocation tab: how the valuation retained at the chosen date splits by instrument, class or asset. */
export function AllocationTab({
  accountId,
  asOf,
  frozen = false,
  title,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  title?: string;
}) {
  const [by, setBy] = useState<AllocationBy>('INSTRUMENT');
  const summary = usePortfolioSummary(accountId, asOf, !frozen);
  const allocation = usePortfolioAllocation(accountId, asOf, by, !frozen);
  return (
    <div className={styles.tab}>
      {allocation.isError ? (
        <OperationsState error title="La répartition est indisponible">
          <button
            className="secondary-action"
            type="button"
            onClick={() => void allocation.refetch()}
          >
            Réessayer
          </button>
        </OperationsState>
      ) : (
        <AllocationCard
          title={title}
          allocation={allocation.data ?? null}
          by={by}
          onByChange={setBy}
          disabled={frozen}
          showFallbackNotice={!summary.data}
        />
      )}
    </div>
  );
}
