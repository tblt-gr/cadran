import type { PortfolioPosition } from '@cadran/api-client';
import { useState } from 'react';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { ReorderDayModal } from '@/features/portfolio/portfolio-account/operations/reorder-day-modal/ReorderDayModal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { workspaceToday } from '@/lib/workspaceTime';
import { PositionLotsModal } from './position-lots-modal/PositionLotsModal';
import { PositionsTable } from './positions-table/PositionsTable';
import { PositionsTableSkeleton } from './positions-table/PositionsTableSkeleton';
import { PositionsToolbar } from './PositionsToolbar';
import { usePositions } from './usePositions';
import styles from './PositionsTab.module.css';

export function PositionsTab({ accountId }: { accountId: string }) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);
  const [asOf, setAsOf] = useState(today);
  const [includeClosed, setIncludeClosed] = useState(false);
  const [lots, setLots] = useState<PortfolioPosition | null>(null);
  const [reorderingDate, setReorderingDate] = useState<string | null>(null);
  const positions = usePositions(accountId, asOf, includeClosed);

  return (
    <div className={styles.tab}>
      <PositionsToolbar
        asOf={asOf}
        includeClosed={includeClosed}
        onAsOfChange={setAsOf}
        onIncludeClosedChange={setIncludeClosed}
        today={today}
      />
      {positions.isPending ? (
        <PositionsTableSkeleton />
      ) : positions.isError ? (
        <OperationsState error title="Les positions sont indisponibles">
          <button
            className="secondary-action"
            onClick={() => void positions.refetch()}
            type="button"
          >
            Réessayer
          </button>
        </OperationsState>
      ) : positions.data.items.length === 0 ? (
        <OperationsState title="Aucune position à cette date">
          <a
            className="secondary-action"
            href={`/portfolios/${accountId}?tab=operations`}
            onClick={(event) =>
              handleClientNavigation(event, `/portfolios/${accountId}?tab=operations`)
            }
          >
            Voir les opérations
          </a>
        </OperationsState>
      ) : (
        <PositionsTable
          items={positions.data.items}
          onLots={setLots}
          onReorder={(position) =>
            setReorderingDate(
              position.flags.find((flag) => flag.code === 'ORDER_DEPENDENT_DAY')?.dates[0] ?? null,
            )
          }
        />
      )}
      {lots ? (
        <PositionLotsModal
          accountId={accountId}
          asOf={asOf}
          onClose={() => setLots(null)}
          position={lots}
        />
      ) : null}
      {reorderingDate ? (
        <ReorderDayModal
          accountId={accountId}
          onClose={() => setReorderingDate(null)}
          onSaved={() => void positions.refetch()}
          tradeDate={reorderingDate}
        />
      ) : null}
    </div>
  );
}
