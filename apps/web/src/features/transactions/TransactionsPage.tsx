import {
  createTransaction,
  createTransfer,
  duplicateTransaction,
  listAccounts,
  listTransactions,
  updateTransaction,
  voidTransaction,
  type CreateTransactionRequest,
  type CreateTransferRequest,
  type Transaction,
  type UpdateTransactionRequest,
} from '@cadran/api-client';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { CategorizationTabs, type Categorization } from './categorization-tabs/CategorizationTabs';
import { TransactionEditor } from './transaction-editor/TransactionEditor';
import { TransactionFilters } from './transaction-filters/TransactionFilters';
import {
  isImpossibleCombination,
  toListTransactionsQuery,
} from './transaction-filters/filterState';
import { useTransactionFilters } from './transaction-filters/useTransactionFilters';
import { TransactionList } from './transaction-list/TransactionList';
import { TransactionListFooter } from './transaction-list/TransactionListFooter';
import { TransactionsState } from './transactions-state/TransactionsState';
import {
  TransactionRequestError,
  transactionErrorKind,
  transactionRequestError,
} from './transactionError';
import { listReferencedAccounts, mergeAccountOptions } from './transactionAccounts';
import { TransferEditor } from './transfer-editor/TransferEditor';
import { RefundEditor } from './refund-editor/RefundEditor';
import { VoidTransactionDialog } from './void-transaction-dialog/VoidTransactionDialog';
import styles from './TransactionsPage.module.css';

type Editor = Transaction | 'create' | null;
type Saved = 'duplicated' | 'saved' | 'transferSaved' | 'voided' | null;

export function TransactionsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const {
    filters: searchFilters,
    reset: resetFilters,
    revision: filtersRevision,
    setFilters,
  } = useTransactionFilters();
  const [cursor, setCursor] = useState<string | null>(null);
  // Paging replaces rather than appends rows, so two consecutive loads can carry the same
  // row count and produce an identical announced string. This monotonic counter changes on
  // every filter change and every page navigation, so the announcement always changes with
  // it and a screen reader re-reads it even when the count happens to stay the same.
  const [loadSequence, setLoadSequence] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [voidingId, setVoidingId] = useState<string | null>(null);
  const [transferEditorOpen, setTransferEditorOpen] = useState(false);
  const [refundTarget, setRefundTarget] = useState<Transaction | null>(null);
  const [saved, setSaved] = useState<Saved>(null);
  const duplicatingIdsRef = useRef(new Set<string>());
  const [duplicatingIds, setDuplicatingIds] = useState<ReadonlySet<string>>(new Set());

  const categorization: Categorization = searchFilters.categorization === 'NONE' ? 'NONE' : 'ALL';

  function updateFilters(next: typeof searchFilters) {
    setCursor(null);
    setFilters(next);
    setLoadSequence((current) => current + 1);
  }

  function resetAllFilters() {
    setCursor(null);
    resetFilters();
    setLoadSequence((current) => current + 1);
  }

  const impossible = isImpossibleCombination(searchFilters);
  const filters = {
    ...toListTransactionsQuery(searchFilters, new Date()),
    cursor: cursor ?? undefined,
    pageSize: 50,
  };

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

  const accounts = useQuery({
    queryKey: ['accounts', { includeArchived: false, includeClosed: false, page: 1 }],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  const transactions = useQuery({
    queryKey: ['transactions', filters],
    queryFn: async ({ signal }) => {
      const result = await listTransactions({
        ...authApiOptions(),
        query: filters,
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    enabled: !impossible,
    placeholderData: keepPreviousData,
    retry: false,
  });
  const staleCursorError =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'staleCursor';
  // The keyset cursor was invalidated by a concurrent change: the rows already on screen are
  // kept, with the footer offering to reload from the first page, instead of blanking the list.
  const lastGoodPage = useRef(transactions.data ?? null);
  if (transactions.data) {
    lastGoodPage.current = transactions.data;
  }
  const displayedPage = transactions.data ?? (staleCursorError ? lastGoodPage.current : null);
  const items = displayedPage?.items ?? [];
  const referencedAccountIds = [...new Set(items.map((transaction) => transaction.accountId))];
  const referencedAccounts = useQuery({
    queryKey: ['transaction-accounts', referencedAccountIds],
    queryFn: ({ signal }) => listReferencedAccounts(referencedAccountIds, signal),
    enabled: referencedAccountIds.length > 0,
    retry: false,
  });
  const activeAccountOptions = accounts.data?.items ?? [];
  const accountOptions = mergeAccountOptions(activeAccountOptions, referencedAccounts.data ?? []);
  const voiding = items.find((transaction) => transaction.id === voidingId) ?? null;

  /**
   * Reloads the list from its first page after a write. Any write moves the workspace watermark,
   * so refetching a later page with its old cursor would only answer `cursor_stale` and leave
   * the pre-write rows on screen. Only first-page queries are refetched now; a later page being
   * displayed is marked stale and replaced by the first page once the cursor is cleared.
   */
  async function reloadAfterWrite() {
    if (cursor !== null) {
      setCursor(null);
      setLoadSequence((current) => current + 1);
    }
    await queryClient.invalidateQueries({ queryKey: ['transactions'], refetchType: 'none' });
    await queryClient.refetchQueries(
      {
        predicate: (query) =>
          query.queryKey[0] === 'transactions' &&
          !(query.queryKey[1] as { cursor?: string } | undefined)?.cursor,
        type: 'active',
      },
      { cancelRefetch: false },
    );
  }

  async function refreshOnStaleTransaction(error: unknown) {
    if (error instanceof TransactionRequestError && error.kind === 'stale') {
      await reloadAfterWrite();
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateTransactionRequest | UpdateTransactionRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateTransaction({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateTransactionRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createTransaction({ ...authApiOptions(), body: body as CreateTransactionRequest }),
            );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved('saved');
      await reloadAfterWrite();
    },
    onError: refreshOnStaleTransaction,
  });

  const transferSave = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const result = await withCsrfRetry(() => createTransfer({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setTransferEditorOpen(false);
      setSaved('transferSaved');
      await reloadAfterWrite();
    },
  });

  const voidMutation = useMutation({
    mutationFn: async (transaction: Transaction) => {
      const result = await withCsrfRetry(() =>
        voidTransaction({
          ...authApiOptions(),
          path: { id: transaction.id },
          body: { version: transaction.version },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleTransaction,
    onSuccess: async () => {
      setVoidingId(null);
      setSaved('voided');
      await reloadAfterWrite();
    },
  });

  const duplicate = useMutation({
    mutationFn: async (transaction: Transaction) => {
      const result = await withCsrfRetry(() =>
        duplicateTransaction({
          ...authApiOptions(),
          path: { id: transaction.id },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setSaved('duplicated');
      await reloadAfterWrite();
    },
    onSettled: (_data, _error, transaction) => {
      duplicatingIdsRef.current.delete(transaction.id);
      setDuplicatingIds(new Set(duplicatingIdsRef.current));
    },
  });

  function duplicateOnce(transaction: Transaction) {
    if (duplicatingIdsRef.current.has(transaction.id)) {
      return;
    }

    duplicatingIdsRef.current.add(transaction.id);
    setDuplicatingIds(new Set(duplicatingIdsRef.current));
    duplicate.mutate(transaction);
  }

  const submitError = transactionErrorKind(save.error, save.isError);
  const voidError = transactionErrorKind(voidMutation.error, voidMutation.isError);
  const duplicateError = transactionErrorKind(duplicate.error, duplicate.isError);
  const unauthorized =
    transactions.error instanceof TransactionRequestError &&
    transactions.error.kind === 'unauthorized';
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

      {transferEditorOpen ? (
        <TransferEditor
          accounts={activeAccountOptions}
          close={closeTransferEditor}
          onSubmit={(body) => transferSave.mutate(body)}
          pending={transferSave.isPending}
          submitError={transactionErrorKind(transferSave.error, transferSave.isError)}
        />
      ) : null}

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`transactions.toasts.${saved}`)}</Toast>
      ) : null}
      {duplicateError ? (
        <p className={styles.alert} role="alert">
          {t(`transactions.errors.${duplicateError}`)}
        </p>
      ) : null}

      {editor ? (
        <TransactionEditor
          accounts={editor === 'create' ? activeAccountOptions : accountOptions}
          close={closeEditor}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          submitError={submitError}
          transaction={editor === 'create' ? undefined : editor}
        />
      ) : null}

      {refundTarget ? (
        <RefundEditor
          accounts={activeAccountOptions}
          close={() => setRefundTarget(null)}
          onSaved={async () => {
            await reloadAfterWrite();
            await queryClient.invalidateQueries({
              queryKey: ['transaction-refundable', refundTarget.id],
            });
          }}
          transaction={refundTarget}
        />
      ) : null}

      {voiding ? (
        <Modal
          close={() => {
            setVoidingId(null);
            voidMutation.reset();
          }}
          eyebrow={t('transactions.void.eyebrow')}
          title={t('transactions.void.title')}
        >
          <VoidTransactionDialog
            onConfirm={() => voidMutation.mutate(voiding)}
            pending={voidMutation.isPending}
            submitError={voidError}
            transaction={voiding}
          />
        </Modal>
      ) : null}

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
            onLoadMore={() => {
              setCursor(displayedPage?.nextCursor ?? null);
              setLoadSequence((current) => current + 1);
            }}
            onReloadFromFirstPage={() => {
              setCursor(null);
              setLoadSequence((current) => current + 1);
            }}
            stale={staleCursorError}
          />
        </>
      )}
    </div>
  );
}
