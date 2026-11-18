import type { Transaction } from '@cadran/api-client';
import type { UseQueryResult } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import type { BulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { TransactionListFooter } from '@/features/transactions/transaction-list/TransactionListFooter';
import { TransactionsState } from '@/features/transactions/transactions-state/TransactionsState';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';

/**
 * The account detail page's movements block: the loading/error/empty/impossible
 * states shared with the Transactions page, and the paginated table itself. Named and self-contained because it owns its own
 * loading/empty/error branching, distinct from the identity and rules above
 * it on the page.
 */
export function AccountMovementsSection({
  bulk = null,
  bulkMode = false,
  canCreate,
  displayedPageHasMore,
  impossible = false,
  items,
  loadingMore,
  onCreate,
  onLoadMore,
  onReloadFromFirstPage,
  onResetFilters,
  queue = false,
  staleCursorError,
  transactions,
  unauthorized,
}: {
  /** Row selection for bulk edits; the mode switch and its controls sit with the filters. */
  bulk?: BulkSelection | null;
  bulkMode?: boolean;
  canCreate: boolean;
  displayedPageHasMore: boolean;
  /** The filters contradict each other: nothing is requested and the state says so. */
  impossible?: boolean;
  items: Transaction[];
  loadingMore: boolean;
  onCreate: () => void;
  onLoadMore: () => void;
  onReloadFromFirstPage: () => void;
  onResetFilters?: () => void;
  /** Only uncategorized transactions are asked for: an empty list reads as a cleared queue. */
  queue?: boolean;
  staleCursorError: boolean;
  transactions: Pick<UseQueryResult, 'isPending' | 'isError' | 'refetch'>;
  unauthorized: boolean;
}) {
  const { t } = useTranslation();

  return (
    <>
      <h3 className="sr-only" tabIndex={-1} data-transaction-focus-fallback>
        {t('accounts.detail.movements.title')}
      </h3>
      {impossible ? (
        <TransactionsState
          canCreate={canCreate}
          kind="impossible"
          onCreate={onCreate}
          onResetFilters={onResetFilters}
          onRetry={() => void transactions.refetch()}
        />
      ) : transactions.isPending ? (
        <TransactionDayList
          headingLevel={4}
          label={t('accounts.detail.movements.caption')}
          transactions={null}
        />
      ) : transactions.isError && !staleCursorError && (items.length === 0 || unauthorized) ? (
        <TransactionsState
          canCreate={canCreate}
          kind={unauthorized ? 'unauthorized' : 'error'}
          onCreate={onCreate}
          onRetry={() => void transactions.refetch()}
        />
      ) : items.length === 0 ? (
        <TransactionsState
          canCreate={canCreate}
          kind={queue ? 'queueEmpty' : 'empty'}
          onCreate={onCreate}
          onRetry={() => void transactions.refetch()}
        />
      ) : (
        <>
          <TransactionDayList
            headingLevel={4}
            label={t('accounts.detail.movements.caption')}
            selection={bulkMode ? bulk?.rowSelection : undefined}
            transactions={items}
          />
          <TransactionListFooter
            failed={transactions.isError && !staleCursorError}
            hasMore={!staleCursorError && displayedPageHasMore}
            loadingMore={loadingMore}
            onLoadMore={onLoadMore}
            onReloadFromFirstPage={onReloadFromFirstPage}
            stale={staleCursorError}
          />
        </>
      )}
    </>
  );
}
