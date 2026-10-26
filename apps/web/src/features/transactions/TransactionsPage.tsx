import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { PeriodClosurePanel } from '@/features/closures/period-closure-panel/PeriodClosurePanel';
import { BulkSelectionToolbar } from './bulk-selection/BulkSelectionToolbar';
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

function navigateTo(href: string) {
  window.history.pushState({}, '', href);
  window.dispatchEvent(new PopStateEvent('popstate'));
}

export function TransactionsPage() {
  const { t } = useTranslation();
  const [editor, setEditor] = useState<Editor>(null);
  const [transferEditorOpen, setTransferEditorOpen] = useState(false);
  const [periodClosureOpen, setPeriodClosureOpen] = useState(false);
  const [bulkMode, setBulkMode] = useState(false);

  const listing = useTransactionsListing();
  const {
    accountOptions,
    activeAccountOptions,
    bulkQuery,
    categorization,
    displayedPage,
    filtersRevision,
    impossible,
    items,
    loadMore,
    loadSequence,
    reloadAfterWrite,
    reloadFromFirstPage,
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
    resetKey: `${loadSequence}:${bulkMode}`,
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
        <HeaderActions>
          <button
            aria-label={t('transactions.add')}
            className={`primary-action ${styles.add}`}
            onClick={() => openEditor('create')}
            type="button"
          >
            <Icon name="add" size={18} />
            <span className={styles.addLabel}>{t('transactions.add')}</span>
          </button>
          <button
            aria-label={t('transactions.addTransfer')}
            className="icon-button"
            onClick={openTransferEditor}
            title={t('transactions.addTransfer')}
            type="button"
          >
            <Icon name="transactions" size={18} />
          </button>
        </HeaderActions>

        <TransactionFilters
          accounts={accountOptions}
          filters={searchFilters}
          onChange={updateFilters}
          onReset={resetAllFilters}
          revision={filtersRevision}
        />

        <HeaderActions>
          <ActionMenu
            items={[
              {
                icon: 'lock',
                id: 'closure',
                label: t('closures.title'),
                onSelect: () => setPeriodClosureOpen(true),
                text: t('closures.title'),
              },
              {
                icon: 'categories',
                id: 'categories',
                label: t('transactions.manageCategories'),
                onSelect: () => navigateTo('/transactions/categories'),
                text: t('transactions.manageCategories'),
              },
              {
                icon: 'repeat',
                id: 'recurrences',
                label: t('transactions.manageRecurrences'),
                onSelect: () => navigateTo('/transactions/recurrences'),
                text: t('transactions.manageRecurrences'),
              },
            ]}
            label={t('transactions.moreActions')}
          />
        </HeaderActions>

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

        {periodClosureOpen ? (
          <PeriodClosurePanel close={() => setPeriodClosureOpen(false)} />
        ) : null}

        {!impossible &&
        !(transactions.isPending && transactions.data === undefined) &&
        !(transactions.isError && !staleCursorError) ? (
          <p aria-live="polite" className="sr-only" role="status">
            {t('transactions.pagination.loadedCount', {
              count: items.length,
              sequence: loadSequence,
            })}
          </p>
        ) : null}

        {impossible ? (
          <TransactionsState
            kind="impossible"
            onCreate={() => openEditor('create')}
            onResetFilters={resetAllFilters}
            onRetry={() => void transactions.refetch()}
          />
        ) : transactions.isPending && transactions.data === undefined ? (
          <TransactionsPageSkeleton />
        ) : transactions.isError && !staleCursorError ? (
          <TransactionsState
            kind={unauthorized ? 'unauthorized' : 'error'}
            onCreate={() => openEditor('create')}
            onRetry={() => void transactions.refetch()}
          />
        ) : items.length === 0 ? (
          <TransactionsState
            kind={categorization === 'NONE' ? 'queueEmpty' : 'empty'}
            onCreate={() => openEditor('create')}
            onRetry={() => void transactions.refetch()}
          />
        ) : (
          <>
            <BulkSelectionToolbar
              active={bulkMode}
              bulk={bulk}
              onToggleActive={() => setBulkMode((active) => !active)}
            />
            <TransactionDayList
              accounts={accountOptions}
              label={t('transactions.list.caption')}
              selection={bulkMode ? bulk.rowSelection : undefined}
              transactions={items}
            />
            <TransactionListFooter
              hasMore={!staleCursorError && Boolean(displayedPage?.nextCursor)}
              loadingMore={transactions.isFetching && !transactions.isPending}
              onLoadMore={loadMore}
              onReloadFromFirstPage={reloadFromFirstPage}
              stale={staleCursorError}
            />
          </>
        )}
      </div>
    </TransactionWorkflow>
  );
}
