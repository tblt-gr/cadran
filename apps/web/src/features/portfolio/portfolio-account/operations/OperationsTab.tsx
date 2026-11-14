import {
  dismissPortfolioDetailSelection,
  instrumentFromLocation,
} from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import type { Account, PortfolioOperation } from '@cadran/api-client';
import { useEffect, useState, type RefObject } from 'react';
import { OperationDetailModal } from './operation-detail-modal/OperationDetailModal';
import { OperationFilters } from './operation-filters/OperationFilters';
import { noFilters, type OperationFilterValues } from './operation-filters/operationFilterValues';
import { OperationFormModal } from './operation-form/OperationFormModal';
import { OperationsState } from './OperationsState';
import { NotPortfolioState } from './NotPortfolioState';
import { OperationsTableSkeleton } from './OperationsTableSkeleton';
import { IncrementalList } from '@/components/ui/incremental-list/IncrementalList';
import { OperationsTable } from './operations-table/OperationsTable';
import { useOperation } from './useOperation';
import { useOperations } from './useOperations';
import { OperationRequestError } from './operationError';
import { VoidOperationModal } from './void-operation-dialog/VoidOperationModal';
import { ReorderDayModal } from './reorder-day-modal/ReorderDayModal';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './OperationsTab.module.css';

/** The Opérations tab: filters, table and the modify, detail and void modals. */
export function OperationsTab({
  account,
  sourceReturnFocus,
  creating,
  onCloseCreate,
  onCreate,
  contractRequired = false,
  includeVoided = false,
}: {
  account: Account;
  asOf?: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
  creating: boolean;
  onCloseCreate: () => void;
  onCreate: () => void;
  contractRequired?: boolean;
  /** Owned by the page header, which carries the toggle. */
  includeVoided?: boolean;
}) {
  const [chosen, setFilters] = useState<OperationFilterValues>(() => ({
    ...noFilters,
    instrumentId: instrumentFromLocation(),
  }));
  const filters = { ...chosen, includeVoided };
  const [editing, setEditing] = useState<PortfolioOperation | null>(null);
  const [voiding, setVoiding] = useState<PortfolioOperation | null>(null);
  const [detailId, setDetailId] = useState<string | null>(() => operationFromLocation());
  const [stale, setStale] = useState(false);
  const [reordering, setReordering] = useState<PortfolioOperation | null>(null);
  const operations = useOperations(account.id, filters);
  const items = operations.data?.items ?? [];
  const total = operations.data?.total ?? 0;
  const detail = useOperation(
    detailId,
    items.find((item) => item.id === detailId),
  );
  useEffect(() => {
    const updateDetail = () => setDetailId(operationFromLocation());
    window.addEventListener('popstate', updateDetail);
    return () => window.removeEventListener('popstate', updateDetail);
  }, []);

  const refusal = operations.error instanceof OperationRequestError ? operations.error : null;
  const filtered =
    filters.types.length > 0 ||
    filters.instrumentId !== null ||
    filters.from !== '' ||
    filters.to !== '';

  function changeFilters(next: OperationFilterValues) {
    setStale(false);
    setFilters(next);
  }

  return (
    <div className={styles.tab}>
      <OperationFilters
        accountKind={account.kind}
        currencyCode={account.assetCode}
        onChange={changeFilters}
        value={filters}
      />
      {stale ? (
        <p className={styles.notice} role="status">
          Cette opération a déjà été modifiée. La liste a été actualisée.
        </p>
      ) : null}
      {operations.isPending ? (
        <OperationsTableSkeleton status="Chargement des opérations…" />
      ) : refusal?.code === 'account_not_portfolio' ? (
        <NotPortfolioState accountId={account.id} />
      ) : operations.isError && !operations.data ? (
        <OperationsState error title="Les opérations sont indisponibles">
          <button
            className="secondary-action"
            onClick={() => void operations.refetch()}
            type="button"
          >
            Réessayer
          </button>
        </OperationsState>
      ) : total === 0 ? (
        <OperationsState
          title={
            filtered || filters.includeVoided ? 'Aucune opération trouvée' : 'Aucune opération'
          }
        >
          {filtered ? null : (
            <button
              className="primary-action"
              disabled={contractRequired}
              onClick={onCreate}
              type="button"
            >
              Enregistrer la première opération
            </button>
          )}
        </OperationsState>
      ) : (
        <IncrementalList items={items} query={operations}>
          {(visibleItems) => (
            <OperationsTable
              items={visibleItems}
              onDetail={(item) => setDetailId(item.id)}
              onEdit={setEditing}
              onVoid={setVoiding}
              onReorder={setReordering}
            />
          )}
        </IncrementalList>
      )}
      {creating ? (
        <OperationFormModal
          account={account}
          onClose={onCloseCreate}
          onSaved={onCloseCreate}
          onStale={() => setStale(true)}
          today={todayInBrowser()}
        />
      ) : null}
      {editing ? (
        <OperationFormModal
          account={account}
          onClose={() => setEditing(null)}
          onSaved={() => setEditing(null)}
          onStale={() => setStale(true)}
          operation={editing}
          today={todayInBrowser()}
        />
      ) : null}
      {voiding ? (
        <VoidOperationModal
          onClose={() => setVoiding(null)}
          onStale={() => setStale(true)}
          operation={voiding}
        />
      ) : null}
      {reordering ? (
        <ReorderDayModal
          accountId={account.id}
          onClose={() => setReordering(null)}
          onSaved={() => void operations.refetch()}
          tradeDate={reordering.tradeDate}
        />
      ) : null}
      {detailId && detail.data ? (
        <OperationDetailModal
          returnFocus={
            detailId === new URLSearchParams(window.location.search).get('operation')
              ? sourceReturnFocus
              : undefined
          }
          onClose={() => {
            dismissPortfolioDetailSelection(sourceReturnFocus);
            setDetailId(null);
          }}
          onOpenOperation={setDetailId}
          operation={detail.data}
        />
      ) : null}
    </div>
  );
}

function operationFromLocation() {
  return new URLSearchParams(window.location.search).get('operation');
}
