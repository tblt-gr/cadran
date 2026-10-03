import type { Account, PortfolioOperation } from '@cadran/api-client';
import { useEffect, useState, type RefObject } from 'react';
import { OperationDetailModal } from './operation-detail-modal/OperationDetailModal';
import { OperationFilters } from './operation-filters/OperationFilters';
import { noFilters, type OperationFilterValues } from './operation-filters/operationFilterValues';
import { OperationFormModal } from './operation-form/OperationFormModal';
import { OperationsState } from './OperationsState';
import { NotPortfolioState } from './NotPortfolioState';
import { OperationsTableSkeleton } from './OperationsTableSkeleton';
import { OperationsTable } from './operations-table/OperationsTable';
import { useOperation } from './useOperation';
import { OPERATIONS_PER_PAGE, useOperations } from './useOperations';
import { OperationRequestError } from './operationError';
import { VoidOperationModal } from './void-operation-dialog/VoidOperationModal';
import { ReorderDayModal } from './reorder-day-modal/ReorderDayModal';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './OperationsTab.module.css';

/** The Opérations tab: filters, table and the modify, detail and void modals. */
export function OperationsTab({
  account,
  asOf,
  sourceReturnFocus,
  creating,
  onCloseCreate,
  onCreate,
}: {
  account: Account;
  asOf?: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
  creating: boolean;
  onCloseCreate: () => void;
  onCreate: () => void;
}) {
  const [filters, setFilters] = useState<OperationFilterValues>(noFilters);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<PortfolioOperation | null>(null);
  const [voiding, setVoiding] = useState<PortfolioOperation | null>(null);
  const [detailId, setDetailId] = useState<string | null>(() => operationFromLocation());
  const [stale, setStale] = useState(false);
  const [reordering, setReordering] = useState<PortfolioOperation | null>(null);
  const operations = useOperations(
    account.id,
    { ...filters, to: asOf && (!filters.to || filters.to > asOf) ? asOf : filters.to },
    page,
  );
  const items = operations.data?.items ?? [];
  const total = operations.data?.total ?? 0;
  const detail = useOperation(
    detailId,
    items.find((item) => item.id === detailId),
  );
  const totalPages = Math.max(1, Math.ceil(total / OPERATIONS_PER_PAGE));
  useEffect(() => {
    if (total > 0 && page > totalPages) setPage(totalPages);
  }, [page, total, totalPages]);
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
    setPage(1);
    setFilters(next);
  }

  return (
    <div className={styles.tab}>
      <OperationFilters currencyCode={account.assetCode} onChange={changeFilters} value={filters} />
      {stale ? (
        <p className={styles.notice} role="status">
          Cette opération a déjà été modifiée. La liste a été actualisée.
        </p>
      ) : null}
      {operations.isPending ? (
        <OperationsTableSkeleton status="Chargement des opérations…" />
      ) : refusal?.code === 'account_not_portfolio' ? (
        <NotPortfolioState accountId={account.id} />
      ) : operations.isError ? (
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
            <button className="primary-action" onClick={onCreate} type="button">
              Enregistrer la première opération
            </button>
          )}
        </OperationsState>
      ) : (
        <OperationsTable
          items={items}
          onDetail={(item) => setDetailId(item.id)}
          onEdit={setEditing}
          onVoid={setVoiding}
          onReorder={setReordering}
        />
      )}
      {operations.isSuccess && total > OPERATIONS_PER_PAGE ? (
        <nav aria-label="Pagination des opérations" className={styles.pagination}>
          <button
            className="secondary-action"
            disabled={page === 1}
            onClick={() => setPage((value) => value - 1)}
            type="button"
          >
            Précédent
          </button>
          <span>
            Page {page} sur {totalPages}
          </span>
          <button
            className="secondary-action"
            disabled={page === totalPages}
            onClick={() => setPage((value) => value + 1)}
            type="button"
          >
            Suivant
          </button>
        </nav>
      ) : null}
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
          onClose={() => setDetailId(null)}
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
