import type { Transaction } from '@cadran/api-client';
import type { UseQueryResult } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import {
  NO_ROW_SELECTION,
  type BulkSelection,
} from '@/features/transactions/bulk-selection/useBulkSelection';
import { TransactionListFooter } from '@/features/transactions/transaction-list/TransactionListFooter';
import { TransactionsState } from '@/features/transactions/transactions-state/TransactionsState';
import { formatCalendarMonth } from '@/lib/decimal';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';
import styles from './AccountMovementsSection.module.css';

/**
 * The account detail page's movements block: the period filter notice, the
 * loading/error/empty states shared with the Transactions page, and the
 * paginated table itself. Named and self-contained because it owns its own
 * loading/empty/error branching, distinct from the identity and rules above
 * it on the page.
 */
export function AccountMovementsSection({
  bulk = null,
  canCreate,
  displayedPageHasMore,
  items,
  language,
  loadingMore,
  month,
  onClearMonth,
  onCreate,
  onLoadMore,
  onReloadFromFirstPage,
  staleCursorError,
  transactions,
  unauthorized,
}: {
  /** Row selection for bulk edits; absent while the account's own data is not ready. */
  bulk?: BulkSelection | null;
  canCreate: boolean;
  displayedPageHasMore: boolean;
  items: Transaction[];
  language: string;
  loadingMore: boolean;
  month: string | null;
  onClearMonth: () => void;
  onCreate: () => void;
  onLoadMore: () => void;
  onReloadFromFirstPage: () => void;
  staleCursorError: boolean;
  transactions: Pick<UseQueryResult, 'isPending' | 'isError' | 'refetch'>;
  unauthorized: boolean;
}) {
  const { t } = useTranslation();

  return (
    <>
      <div className={styles.movementsHeading}>
        <h3 className={styles.movementsTitle} tabIndex={-1} data-transaction-focus-fallback>
          {t('accounts.detail.movements.title')}
        </h3>
        {month !== null ? (
          <p className={styles.periodNotice}>
            {t('accounts.detail.movements.periodActive', {
              month: formatCalendarMonth(`${month}-01`, language),
            })}
            <button className="secondary-action" onClick={onClearMonth} type="button">
              {t('accounts.detail.movements.clearPeriod')}
            </button>
          </p>
        ) : null}
      </div>

      {transactions.isPending ? (
        <>
          <BulkSelectionToolbar bulk={null} />
          <TransactionDayList
            headingLevel={4}
            label={t('accounts.detail.movements.caption')}
            selection={NO_ROW_SELECTION}
            transactions={null}
          />
        </>
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
          kind="empty"
          onCreate={onCreate}
          onRetry={() => void transactions.refetch()}
        />
      ) : (
        <>
          <BulkSelectionToolbar bulk={bulk} />
          <TransactionDayList
            headingLevel={4}
            label={t('accounts.detail.movements.caption')}
            selection={bulk?.rowSelection}
            transactions={items}
          />
          {transactions.isError && !staleCursorError ? (
            <div role="alert">
              <p>{t('transactions.error.description')}</p>
              <button
                className="secondary-action"
                type="button"
                onClick={() => void transactions.refetch()}
              >
                {t('foundation.retry')}
              </button>
            </div>
          ) : (
            <TransactionListFooter
              hideEndOfList
              hasMore={!staleCursorError && displayedPageHasMore}
              loadingMore={loadingMore}
              onLoadMore={onLoadMore}
              onReloadFromFirstPage={onReloadFromFirstPage}
              stale={staleCursorError}
            />
          )}
        </>
      )}
    </>
  );
}
