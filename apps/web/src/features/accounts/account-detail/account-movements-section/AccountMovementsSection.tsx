import type { Transaction } from '@cadran/api-client';
import type { UseQueryResult } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { TransactionListFooter } from '@/features/transactions/transaction-list/TransactionListFooter';
import { TransactionsState } from '@/features/transactions/transactions-state/TransactionsState';
import { formatCalendarMonth } from '@/lib/decimal';
import { AccountMovementsTable } from '@/features/accounts/account-detail/account-movements/AccountMovementsTable';
import styles from './AccountMovementsSection.module.css';

/**
 * The account detail page's movements block: the period filter notice, the
 * loading/error/empty states shared with the Transactions page, and the
 * paginated table itself. Named and self-contained because it owns its own
 * loading/empty/error branching, distinct from the identity and rules above
 * it on the page.
 */
export function AccountMovementsSection({
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
        <h3 className={styles.movementsTitle}>{t('accounts.detail.movements.title')}</h3>
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
        <TransactionsState
          canCreate={canCreate}
          kind="loading"
          onCreate={onCreate}
          onRetry={() => void transactions.refetch()}
        />
      ) : transactions.isError && !staleCursorError ? (
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
          <AccountMovementsTable transactions={items} />
          <TransactionListFooter
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
