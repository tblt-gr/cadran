import type { Transaction } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { PeriodClosurePanel } from '@/features/closures/period-closure-panel/PeriodClosurePanel';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { CategorizationTabs } from './categorization-tabs/CategorizationTabs';
import { TransactionList } from './transaction-list/TransactionList';
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
  const queryClient = useQueryClient();
  const [editor, setEditor] = useState<Editor>(null);
  const [voidingId, setVoidingId] = useState<string | null>(null);
  const [transferEditorOpen, setTransferEditorOpen] = useState(false);
  const [refundTarget, setRefundTarget] = useState<Transaction | null>(null);

  const listing = useTransactionsListing();
  const {
    accountOptions,
    activeAccountOptions,
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

  const mutations = useTransactionMutations({
    editor,
    onSaved: () => setEditor(null),
    onTransferSaved: () => setTransferEditorOpen(false),
    onVoided: () => setVoidingId(null),
    reloadAfterWrite,
  });
  const {
    duplicateError,
    duplicateOnce,
    duplicatingIds,
    save,
    saved,
    setSaved,
    submitError,
    transferSave,
    voidError,
    voidMutation,
  } = mutations;

  const voiding = items.find((transaction) => transaction.id === voidingId) ?? null;

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

  function closeVoiding() {
    setVoidingId(null);
    voidMutation.reset();
  }

  async function handleRefundSaved() {
    await reloadAfterWrite();
    if (refundTarget) {
      await queryClient.invalidateQueries({
        queryKey: ['transaction-refundable', refundTarget.id],
      });
    }
  }

  return (
    <div className={styles.page}>
      <section aria-labelledby="transactions-intro-title" className={styles.intro}>
        <div>
          <p>{t('transactions.eyebrow')}</p>
          <h2 id="transactions-intro-title">{t('transactions.title')}</h2>
          <span>{t('transactions.description')}</span>
        </div>
        <div className={styles.actions}>
          <a
            className="secondary-action"
            href="/transactions/recurrences"
            onClick={(event) => handleClientNavigation(event, '/transactions/recurrences')}
          >
            {t('transactions.manageRecurrences')}
          </a>
          <button className="secondary-action" onClick={openTransferEditor} type="button">
            {t('transactions.addTransfer')}
          </button>
          <button className="primary-action" onClick={() => openEditor('create')} type="button">
            {t('transactions.add')}
          </button>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`transactions.toasts.${saved}`)}</Toast>
      ) : null}
      {duplicateError ? (
        <p className={styles.alert} role="alert">
          {t(`transactions.errors.${duplicateError}`)}
        </p>
      ) : null}

      <TransactionModals
        accountOptions={accountOptions}
        activeAccountOptions={activeAccountOptions}
        closeEditor={closeEditor}
        closeTransferEditor={closeTransferEditor}
        closeVoiding={closeVoiding}
        editor={editor}
        onRefundClosed={() => setRefundTarget(null)}
        onRefundSaved={handleRefundSaved}
        refundTarget={refundTarget}
        save={save}
        submitError={submitError}
        transferEditorOpen={transferEditorOpen}
        transferSave={transferSave}
        voidError={voidError}
        voidMutation={voidMutation}
        voiding={voiding}
      />

      <PeriodClosurePanel />

      <CategorizationTabs
        onChange={(next) =>
          updateFilters({ ...searchFilters, categorization: next === 'NONE' ? 'NONE' : 'ANY' })
        }
        value={categorization}
      />

      <TransactionFilters
        accounts={accountOptions}
        filters={searchFilters}
        onChange={updateFilters}
        onReset={resetAllFilters}
        revision={filtersRevision}
      />

      <p aria-live="polite" className="sr-only" role="status">
        {impossible || transactions.isPending || (transactions.isError && !staleCursorError)
          ? ''
          : t('transactions.pagination.loadedCount', {
              count: items.length,
              sequence: loadSequence,
            })}
      </p>

      {impossible ? (
        <TransactionsState
          kind="impossible"
          onCreate={() => openEditor('create')}
          onResetFilters={resetAllFilters}
          onRetry={() => void transactions.refetch()}
        />
      ) : transactions.isPending ? (
        <TransactionsState
          kind="loading"
          onCreate={() => openEditor('create')}
          onRetry={() => void transactions.refetch()}
        />
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
          <TransactionList
            accounts={accountOptions}
            duplicatingIds={duplicatingIds}
            onDuplicate={duplicateOnce}
            onEdit={(transaction) => openEditor(transaction)}
            onRefund={setRefundTarget}
            onVoid={(transaction) => setVoidingId(transaction.id)}
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
  );
}
