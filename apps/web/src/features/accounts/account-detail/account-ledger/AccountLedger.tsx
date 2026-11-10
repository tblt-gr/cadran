import { MonthStepper } from '@/features/transactions/month-stepper/MonthStepper';
import { shiftMonth } from '@/features/transactions/transaction-filters/filterState';
import { TransactionFilters } from '@/features/transactions/transaction-filters/TransactionFilters';
import { useTransactionFilters } from '@/features/transactions/transaction-filters/useTransactionFilters';
import { listAccounts, type Account, type CreateTransactionRequest } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { useBulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import { TransactionWorkflow } from '@/features/transactions/transaction-workflow/TransactionWorkflow';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { transactionErrorKind } from '@/features/transactions/transactionError';
import { AccountMovementsSection } from '@/features/accounts/account-detail/account-movements-section/AccountMovementsSection';
import { useAccountMovements } from '@/features/accounts/account-detail/account-movements/useAccountMovements';
import { useAccountDetailMutations } from '@/features/accounts/account-detail/useAccountDetailMutations';

/** The creation modal of the ledger the page opens, one at a time. */
export type LedgerEditor = 'transaction' | 'transfer' | null;

/** The account page owns its tab; the filters own every other query parameter. */
const PAGE_PARAMETERS = ['tab'] as const;

/**
 * The bank movements of one account with their creation and edit modals: shared by the account
 * page and the account tab of a portfolio, which each place the entry buttons themselves.
 */
export function AccountLedger({
  account,
  creatable,
  editor,
  onEditorChange,
  onSaved,
  refreshError,
  showMovements = true,
}: {
  account: Account;
  showMovements?: boolean;
  creatable: boolean;
  editor: LedgerEditor;
  onEditorChange: (editor: LedgerEditor) => void;
  onSaved: (saved: 'transactionSaved' | 'transferSaved') => void;
  refreshError?: { message: string; retry: () => void };
}) {
  const {
    filters,
    reset: resetFilters,
    revision,
    setFilters,
  } = useTransactionFilters(PAGE_PARAMETERS);
  const {
    bulkQuery,
    displayedPage,
    impossible,
    items,
    loadMore,
    reloadAfterWrite,
    reloadFromFirstPage,
    resetKey,
    staleCursorError,
    transactions,
    unauthorized,
  } = useAccountMovements(account.id, filters);
  const [bulkMode, setBulkMode] = useState(false);
  const bulk = useBulkSelection({
    hasMore: !staleCursorError && Boolean(displayedPage?.nextCursor),
    items,
    query: bulkQuery,
    reloadAfterWrite,
    resetKey: JSON.stringify([resetKey, bulkMode]),
  });

  const activeAccounts = useQuery({
    queryKey: ['accounts', 'account-detail-transfer'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, pageSize: 100 },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });
  const transferAccounts = [
    account,
    ...(activeAccounts.data?.items ?? []).filter((item) => item.id !== account.id),
  ];

  const { saveTransaction, saveTransfer, completeTransfer } = useAccountDetailMutations({
    accountId: account.id,
    reloadAfterWrite,
    onTransactionSaved: () => {
      onEditorChange(null);
      onSaved('transactionSaved');
    },
    onTransferSaved: () => {
      onEditorChange(null);
      onSaved('transferSaved');
    },
  });

  function close() {
    onEditorChange(null);
    saveTransaction.reset();
    saveTransfer.reset();
  }

  return (
    <>
      {editor === 'transaction' ? (
        <TransactionEditor
          accounts={[account]}
          defaultAccountId={account.id}
          close={close}
          onSubmit={(body) => saveTransaction.mutate(body as CreateTransactionRequest)}
          pending={saveTransaction.isPending}
          submitError={transactionErrorKind(saveTransaction.error, saveTransaction.isError)}
        />
      ) : null}

      {editor === 'transfer' ? (
        <TransferEditor
          accounts={transferAccounts}
          close={close}
          defaults={{ lockedSourceAccountId: account.id }}
          onSubmit={(body) => saveTransfer.mutateAsync(body)}
          onSaved={completeTransfer}
          refreshError={refreshError}
          pending={saveTransfer.isPending}
          submitError={transactionErrorKind(saveTransfer.error, saveTransfer.isError)}
        />
      ) : null}

      {showMovements ? (
        <TransactionFilters
          accountLocked
          accounts={[account]}
          disabled={transactions.isPending && !impossible}
          filters={filters}
          onChange={setFilters}
          onReset={resetFilters}
          revision={revision}
        >
          {filters.period === 'month' ? (
            <MonthStepper
              disabled={transactions.isPending && !impossible}
              month={filters.month}
              onStep={(offset) =>
                setFilters({ ...filters, month: shiftMonth(filters.month, offset) }, 'push')
              }
            />
          ) : null}
          <TransactionWorkflow
            accounts={transferAccounts}
            transactions={items}
            reloadAfterWrite={reloadAfterWrite}
          >
            <AccountMovementsSection
              bulk={bulk}
              bulkMode={bulkMode}
              onBulkModeChange={setBulkMode}
              canCreate={creatable}
              displayedPageHasMore={Boolean(displayedPage?.nextCursor)}
              impossible={impossible}
              items={items}
              loadingMore={transactions.isFetching && !transactions.isPending}
              onCreate={() => onEditorChange('transaction')}
              onLoadMore={loadMore}
              onReloadFromFirstPage={reloadFromFirstPage}
              onResetFilters={resetFilters}
              queue={filters.categorization === 'NONE'}
              staleCursorError={staleCursorError}
              transactions={transactions}
              unauthorized={unauthorized}
            />
          </TransactionWorkflow>
        </TransactionFilters>
      ) : null}
    </>
  );
}
