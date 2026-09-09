import {
  createTransaction,
  duplicateTransaction,
  listAccounts,
  listTransactions,
  updateTransaction,
  voidTransaction,
  type CreateTransactionRequest,
  type Transaction,
  type UpdateTransactionRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { TransactionEditor } from './transaction-editor/TransactionEditor';
import { TransactionList } from './transaction-list/TransactionList';
import { TransactionsState } from './transactions-state/TransactionsState';
import {
  TransactionRequestError,
  transactionErrorKind,
  transactionRequestError,
} from './transactionError';
import { listReferencedAccounts, mergeAccountOptions } from './transactionAccounts';
import { VoidTransactionDialog } from './void-transaction-dialog/VoidTransactionDialog';
import styles from './TransactionsPage.module.css';

type Editor = Transaction | 'create' | null;

export function TransactionsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [accountId, setAccountId] = useState('');
  const [includeVoided, setIncludeVoided] = useState(false);
  const [cursor, setCursor] = useState<string | null>(null);
  const [editor, setEditor] = useState<Editor>(null);
  const [voidingId, setVoidingId] = useState<string | null>(null);
  const [saved, setSaved] = useState<'saved' | 'voided' | 'duplicated' | null>(null);
  const duplicatingIdsRef = useRef(new Set<string>());
  const [duplicatingIds, setDuplicatingIds] = useState<ReadonlySet<string>>(new Set());

  const filters = {
    accountId: accountId === '' ? undefined : accountId,
    includeVoided,
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
    retry: false,
  });
  const items = transactions.data?.items ?? [];
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

  async function refreshOnStaleTransaction(error: unknown) {
    if (error instanceof TransactionRequestError && error.kind === 'stale') {
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
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
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
    },
    onError: refreshOnStaleTransaction,
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
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
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
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
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
        <button className="primary-action" onClick={() => openEditor('create')} type="button">
          {t('transactions.add')}
        </button>
      </section>

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

      <div className={styles.toolbar}>
        <label>
          <span className="sr-only">{t('transactions.fields.account')}</span>
          <select
            onChange={(event) => {
              setAccountId(event.target.value);
              setCursor(null);
            }}
            value={accountId}
          >
            <option value="">{t('transactions.filters.allAccounts')}</option>
            {accountOptions.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          <input
            checked={includeVoided}
            onChange={(event) => {
              setIncludeVoided(event.target.checked);
              setCursor(null);
            }}
            type="checkbox"
          />
          <span>{t('transactions.includeVoided')}</span>
        </label>
      </div>

      {transactions.isPending ? (
        <TransactionsState
          kind="loading"
          onCreate={() => openEditor('create')}
          onRetry={() => void transactions.refetch()}
        />
      ) : transactions.isError ? (
        <TransactionsState
          kind={unauthorized ? 'unauthorized' : 'error'}
          onCreate={() => openEditor('create')}
          onRetry={() => void transactions.refetch()}
        />
      ) : items.length === 0 ? (
        <TransactionsState
          kind="empty"
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
            onVoid={(transaction) => setVoidingId(transaction.id)}
            transactions={items}
          />
          {transactions.data?.nextCursor ? (
            <div className={styles.pagination}>
              <button
                className="secondary-action"
                onClick={() => setCursor(transactions.data.nextCursor)}
                type="button"
              >
                {t('transactions.pagination.next')}
              </button>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}
