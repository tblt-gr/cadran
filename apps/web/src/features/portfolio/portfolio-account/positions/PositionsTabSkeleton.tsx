import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { PositionsTableSkeleton } from './positions-table/PositionsTableSkeleton';
import { PositionsToolbar } from './PositionsToolbar';
import styles from './PositionsTab.module.css';

/** Faithful cold-load layout used before the account or positions response is available. */
export function PositionsTabSkeleton({
  status = 'Chargement des positions…',
}: {
  status?: string;
}) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);

  return (
    <div className={styles.tab}>
      <PositionsToolbar
        hideDate
        asOf={today}
        disabled
        includeClosed={false}
        onAsOfChange={() => undefined}
        onIncludeClosedChange={() => undefined}
        today={today}
      />
      <PositionsTableSkeleton status={status} />
    </div>
  );
}
