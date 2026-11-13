import { TransactionAccountNotice } from './transaction-account-notice/TransactionAccountNotice';
import { TransactionsHeaderActions } from './transactions-header-actions/TransactionsHeaderActions';
import { BudgetNavigation } from '@/features/budget/budget-navigation/BudgetNavigation';
import { budgetReturnHref } from '@/features/budget/budget-navigation/budgetNavigation';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { encodeFilters } from './transaction-filters/filterState';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { BulkSelectionPen, BulkSelectionToolbar } from './bulk-selection/BulkSelectionToolbar';
import { useBulkSelection } from './bulk-selection/useBulkSelection';
import { TransactionWorkflow } from './transaction-workflow/TransactionWorkflow';
import { TransactionDayList } from './transaction-list/transaction-day-list/TransactionDayList';
import { TransactionsPageSkeleton } from './TransactionsPageSkeleton';
import { TransactionListFooter } from './transaction-list/TransactionListFooter';
import { TransactionFilters } from './transaction-filters/TransactionFilters';
import { TransactionModals } from './transaction-modals/TransactionModals';
import { TransactionsState } from './transactions-state/TransactionsState';
import type { Editor } from './transactionEditorState';
import { useTransactionMutations } from './useTransactionMutations';
import { useTransactionsListing } from './useTransactionsListing';
import styles from './TransactionsPage.module.css';

export function TransactionsPage() {
  const { t } = useTranslation();
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const [editor, setEditor] = useState<Editor>(null);
  const [transferEditorOpen, setTransferEditorOpen] = useState(false);
  const [bulkMode, setBulkMode] = useState(false);

  const listing = useTransactionsListing();
  const {
    accountOptions,
    accountsPending,
    activeAccountOptions,
    bulkQuery,
    displayedPage,
    filtersRevision,
    impossible,
    items,
    loadMore,
    loadSequence,
    reloadAfterWrite,
    reloadFromFirstPage,
    resetKey,
    resetAllFilters,
    searchFilters,
    staleCursorError,
    transactions,
    unauthorized,
    updateFilters,
  } = listing;

  const bulk = useBulkSelection({
    hasMore: !staleCursorError && Boolean(displayedPage?.nextCursor),
    items,
    query: bulkQuery,
    reloadAfterWrite,
    // Leaving the mode is a reset too: the selection never outlives it.
    resetKey: `${resetKey}:${bulkMode}`,
  });

  const mutations = useTransactionMutations({
    editor,
    onSaved: () => setEditor(null),
    onTransferSaved: () => setTransferEditorOpen(false),
    onVoided: () => {},
    reloadAfterWrite,
  });
  const { save, saved, setSaved, submitError, transferSave, completeTransfer } = mutations;

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    save.reset();
    setEditor(target);
  }

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function openTransferEditor() {
    setSaved(null);
    transferSave.reset();
    setTransferEditorOpen(true);
  }

  function closeTransferEditor() {
    setTransferEditorOpen(false);
    transferSave.reset();
  }

  const initialLoading =
    !impossible &&
    ((transactions.isPending && transactions.data === undefined) || listing.accountContextPending);

  return (
    <TransactionWorkflow
      accounts={accountOptions}
      transactions={items}
      reloadAfterWrite={reloadAfterWrite}
    >
      <div className={styles.page}>
        <h2 className="sr-only" tabIndex={-1} data-transaction-focus-fallback>
          {t('transactions.list.caption')}
        </h2>
        <TransactionsHeaderActions
          onCreate={() => openEditor('create')}
          onTransfer={openTransferEditor}
        />

        {saved ? (
          <Toast onDismiss={() => setSaved(null)}>{t(`transactions.toasts.${saved}`)}</Toast>
        ) : null}

        <TransactionModals
          accountOptions={accountOptions}
          activeAccountOptions={activeAccountOptions}
          defaultAccountId={
            searchFilters.accountId.length === 1 ? searchFilters.accountId[0] : undefined
          }
          closeEditor={closeEditor}
          closeTransferEditor={closeTransferEditor}
          closeVoiding={() => {}}
          editor={editor}
          onRefundClosed={() => {}}
          onRefundSaved={reloadAfterWrite}
          refundTarget={null}
          save={save}
          submitError={submitError}
          transferEditorOpen={transferEditorOpen}
          transferSave={transferSave}
          onTransferSaved={completeTransfer}
          voidError={mutations.voidError}
          voidMutation={mutations.voidMutation}
          voiding={null}
        />

        {!impossible && !initialLoading && !(transactions.isError && !staleCursorError) ? (
          <p aria-live="polite" className="sr-only" role="status">
            {t('transactions.pagination.loadedCount', {
              count: items.length,
              sequence: loadSequence,
            })}
          </p>
        ) : null}

        <BudgetNavigation
          current="transactions"
          month={searchFilters.period === 'month' ? searchFilters.month : today.slice(0, 7)}
          budgetHref={budgetReturnHref(
            window.location.search,
            searchFilters.period === 'month' ? searchFilters.month : null,
            today.slice(0, 7),
          )}
        />
        {!unauthorized && items.length > 0 ? (
          <TransactionAccountNotice
            failed={listing.accountContextFailed}
            onRetry={listing.retryAccountContext}
          />
        ) : null}
        <TransactionFilters
          accounts={accountOptions}
          accountsPending={accountsPending}
          actions={
            <BulkSelectionPen
              active={bulkMode}
              bulk={initialLoading || impossible || items.length === 0 ? null : bulk}
              inline
              onToggleActive={() => setBulkMode((active) => !active)}
            />
          }
          afterChips={
            <BulkSelectionToolbar
              active={bulkMode}
              bulk={initialLoading || impossible || items.length === 0 ? null : bulk}
              inline
              onToggleActive={() => setBulkMode((active) => !active)}
            />
          }
          disabled={initialLoading}
          facetsRequest={{ enabled: !impossible, query: bulkQuery }}
          filters={searchFilters}
          onChange={updateFilters}
          onReset={resetAllFilters}
          revision={filtersRevision}
        >
          {impossible ? (
            <TransactionsState
              kind="impossible"
              onCreate={() => openEditor('create')}
              onResetFilters={resetAllFilters}
              onRetry={() => void transactions.refetch()}
            />
          ) : initialLoading ? (
            <TransactionsPageSkeleton />
          ) : transactions.isError && !staleCursorError && (items.length === 0 || unauthorized) ? (
            <TransactionsState
              kind={unauthorized ? 'unauthorized' : 'error'}
              onCreate={() => openEditor('create')}
              onRetry={() => void transactions.refetch()}
            />
          ) : items.length === 0 ? (
            <TransactionsState
              kind={
                searchFilters.categorization === 'NONE' && !searchFilters.q
                  ? 'queueEmpty'
                  : encodeFilters(searchFilters).size > 0
                    ? 'filteredEmpty'
                    : 'empty'
              }
              onResetFilters={resetAllFilters}
              onCreate={() => openEditor('create')}
              onRetry={() => void transactions.refetch()}
            />
          ) : (
            <>
              <TransactionDayList
                accounts={accountOptions}
                label={t('transactions.list.caption')}
                selection={bulkMode ? bulk.rowSelection : undefined}
                transactions={items}
              />
              <TransactionListFooter
                failed={transactions.isError && !staleCursorError}
                hasMore={!staleCursorError && Boolean(displayedPage?.nextCursor)}
                loadingMore={transactions.isFetching && !transactions.isPending}
                onLoadMore={loadMore}
                onReloadFromFirstPage={reloadFromFirstPage}
                stale={staleCursorError}
              />
            </>
          )}
        </TransactionFilters>
      </div>
    </TransactionWorkflow>
  );
}
