import { BulkSelectionToolbar } from '@/features/transactions/bulk-selection/BulkSelectionToolbar';
import { AccountOperationFilters } from '@/features/accounts/account-detail/account-operation-filters/AccountOperationFilters';
import {
  emptyMovementFilters,
  monthBounds,
  type AccountMovementFilters,
} from '@/features/accounts/account-detail/account-movements/accountMovementFilters';
import { listAccounts, type Account, type CreateTransactionRequest } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { useBulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import type { TransferFormDefaults } from '@/features/transactions/transfer-editor/TransferForm';
import { TransactionWorkflow } from '@/features/transactions/transaction-workflow/TransactionWorkflow';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { transactionErrorKind } from '@/features/transactions/transactionError';
import { AccountMovementsSection } from '@/features/accounts/account-detail/account-movements-section/AccountMovementsSection';
import { useAccountMovements } from '@/features/accounts/account-detail/account-movements/useAccountMovements';
import { useAccountDetailMutations } from '@/features/accounts/account-detail/useAccountDetailMutations';

/** The creation modal of the ledger the page opens, one at a time. */
export type LedgerEditor = 'transaction' | 'transfer' | null;

function initialMonthFromLocation(): string | null {
  const month = new URLSearchParams(window.location.search).get('month');
  return month && /^\d{4}-\d{2}$/.test(month) ? month : null;
}

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
  transferDefaults,
  showMovements = true,
}: {
  account: Account;
  showMovements?: boolean;
  creatable: boolean;
  editor: LedgerEditor;
  onEditorChange: (editor: LedgerEditor) => void;
  onSaved: (saved: 'transactionSaved' | 'transferSaved') => void;
  refreshError?: { message: string; retry: () => void };
  transferDefaults?: TransferFormDefaults;
}) {
  const { i18n } = useTranslation();
  const [month, setMonth] = useState(initialMonthFromLocation);
  const [filters, setFilters] = useState<AccountMovementFilters>(() => ({
    ...emptyMovementFilters,
    ...monthBounds(initialMonthFromLocation()),
  }));
  const {
    bulkQuery,
    displayedPage,
    items,
    loadMore,
    reloadAfterWrite,
    reloadFromFirstPage,
    staleCursorError,
    transactions,
    unauthorized,
  } = useAccountMovements(account.id, month, filters);
  const [bulkMode, setBulkMode] = useState(false);
  const bulk = useBulkSelection({
    hasMore: !staleCursorError && Boolean(displayedPage?.nextCursor),
    items,
    query: bulkQuery,
    reloadAfterWrite,
    resetKey: JSON.stringify([account.id, filters, bulkMode]),
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
          defaults={transferDefaults}
          onSubmit={(body) => saveTransfer.mutateAsync(body)}
          onSaved={completeTransfer}
          refreshError={refreshError}
          pending={saveTransfer.isPending}
          submitError={transactionErrorKind(saveTransfer.error, saveTransfer.isError)}
        />
      ) : null}

      {showMovements ? (
        <AccountOperationFilters
          actions={
            <BulkSelectionToolbar
              active={bulkMode}
              bulk={transactions.isPending ? null : bulk}
              inline
              onToggleActive={() => setBulkMode(!bulkMode)}
            />
          }
          value={filters}
          onChange={(next) => {
            setMonth(null);
            setFilters(next);
          }}
        />
      ) : null}
      {showMovements ? (
        <TransactionWorkflow
          accounts={transferAccounts}
          transactions={items}
          reloadAfterWrite={reloadAfterWrite}
        >
          <AccountMovementsSection
            showBulkToolbar={false}
            bulk={bulk}
            bulkMode={bulkMode}
            onBulkModeChange={setBulkMode}
            canCreate={creatable}
            displayedPageHasMore={Boolean(displayedPage?.nextCursor)}
            items={items}
            language={i18n.language}
            loadingMore={transactions.isFetching && !transactions.isPending}
            month={month}
            onClearMonth={() => {
              setMonth(null);
              setFilters(emptyMovementFilters);
            }}
            onCreate={() => onEditorChange('transaction')}
            onLoadMore={loadMore}
            onReloadFromFirstPage={reloadFromFirstPage}
            staleCursorError={staleCursorError}
            transactions={transactions}
            unauthorized={unauthorized}
          />
        </TransactionWorkflow>
      ) : null}
    </>
  );
}
