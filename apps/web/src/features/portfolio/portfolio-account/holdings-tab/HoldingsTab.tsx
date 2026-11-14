import { AllocationTab } from '@/features/portfolio/portfolio-account/allocation-tab/AllocationTab';
import { PositionsTab } from '@/features/portfolio/portfolio-account/positions/PositionsTab';
import { PositionsTabSkeleton } from '@/features/portfolio/portfolio-account/positions/PositionsTabSkeleton';
import styles from './HoldingsTab.module.css';

/** The Positions tab of a securities account: the lines held, then how the retained valuation splits. */
export function HoldingsTab({
  accountId,
  asOf,
  frozen = false,
  includeClosed = false,
  status,
  onTakeover,
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  includeClosed?: boolean;
  status?: string;
  onTakeover?: () => void;
}) {
  return (
    <div className={styles.tab}>
      {frozen ? (
        <PositionsTabSkeleton status={status} />
      ) : (
        <PositionsTab
          accountId={accountId}
          asOf={asOf}
          includeClosed={includeClosed}
          onTakeover={onTakeover}
        />
      )}
      <AllocationTab accountId={accountId} asOf={asOf} frozen={frozen} />
    </div>
  );
}
