import {
  archiveAccount,
  createAccount,
  listAccounts,
  updateAccount,
  type Account,
  type CreateAccountRequest,
  type UpdateAccountRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  accountErrorKind,
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from './accountError';
import { AccountFilters } from './account-filters/AccountFilters';
import { AccountForm } from './account-form/AccountForm';
import { AccountList } from './account-list/AccountList';
import { AccountPagination } from './account-pagination/AccountPagination';
import { AccountsState } from './accounts-state/AccountsState';
import { ArchiveAccountDialog } from './archive-account-dialog/ArchiveAccountDialog';
import styles from './AccountsPage.module.css';

const PAGE_SIZE = 50;

type Editor = Account | 'create' | null;

export function AccountsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeClosed, setIncludeClosed] = useState(false);
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<Account | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);

  const accounts = useQuery({
    queryKey: ['accounts', includeArchived, includeClosed, page],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived, includeClosed, page, perPage: PAGE_SIZE },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  /**
   * A stale version or an archived account means the list this page shows is
   * behind the server. Refetching it turns the next attempt into a decision on
   * current data instead of a second rejection.
   */
  async function refreshOnStaleState(error: unknown) {
    if (
      error instanceof AccountRequestError &&
      (error.kind === 'stale' || error.kind === 'archived')
    ) {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateAccountRequest | UpdateAccountRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateAccount({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateAccountRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createAccount({ ...authApiOptions(), body: body as CreateAccountRequest }),
            );

      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      setEditor(null);
      setSaved('saved');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  const archive = useMutation({
    mutationFn: async (account: Account) => {
      const result = await withCsrfRetry(() =>
        archiveAccount({
          ...authApiOptions(),
          path: { id: account.id },
          body: { version: account.version },
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      setArchiving(null);
      setSaved('archived');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function closeArchive() {
    setArchiving(null);
    archive.reset();
  }

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    save.reset();
    setEditor(target);
  }

  function openArchive(account: Account) {
    setSaved(null);
    archive.reset();
    setArchiving(account);
  }

  const unauthorized =
    accounts.error instanceof AccountRequestError && accounts.error.status === 401;
  const items = accounts.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((accounts.data?.total ?? 0) / PAGE_SIZE));

  // The result set can shrink under the current page (a concurrent archive, a refetch on focus).
  // React re-renders with the corrected page before committing, so the empty state is never shown
  // for a workspace that still has accounts.
  if (accounts.data && page > totalPages) {
    setPage(totalPages);
  }

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="account-intro-title">
        <div>
          <p>{t('accounts.eyebrow')}</p>
          <h2 id="account-intro-title">{t('accounts.title')}</h2>
          <span>{t('accounts.description')}</span>
        </div>
        <button className="primary-action" onClick={() => openEditor('create')} type="button">
          {t('accounts.add')}
        </button>
      </section>

      {saved ? (
        <p className={styles.success} role="status">
          {t(saved === 'archived' ? 'accounts.archived' : 'accounts.saved')}
        </p>
      ) : null}

      {editor ? (
        <Modal
          close={closeEditor}
          eyebrow={t(
            editor === 'create' ? 'accounts.form.createEyebrow' : 'accounts.form.editEyebrow',
          )}
          title={t(editor === 'create' ? 'accounts.form.createTitle' : 'accounts.form.editTitle')}
        >
          <AccountForm
            account={editor === 'create' ? undefined : editor}
            key={editor === 'create' ? 'create' : editor.id}
            onCancel={closeEditor}
            onSubmit={(body) => save.mutate(body)}
            pending={save.isPending}
            submitError={accountErrorKind(save.error, save.isError)}
          />
        </Modal>
      ) : null}

      {archiving ? (
        <Modal
          close={closeArchive}
          eyebrow={t('accounts.archive.eyebrow')}
          title={t('accounts.archive.title')}
        >
          <ArchiveAccountDialog
            account={archiving}
            onCancel={closeArchive}
            onConfirm={() => archive.mutate(archiving)}
            pending={archive.isPending}
            submitError={accountErrorKind(archive.error, archive.isError)}
          />
        </Modal>
      ) : null}

      <AccountFilters
        includeArchived={includeArchived}
        includeClosed={includeClosed}
        onIncludeArchivedChange={(checked) => {
          setIncludeArchived(checked);
          setPage(1);
        }}
        onIncludeClosedChange={(checked) => {
          setIncludeClosed(checked);
          setPage(1);
        }}
      />

      {accounts.isPending || accounts.isError || items.length === 0 ? (
        <AccountsState
          kind={
            accounts.isPending
              ? 'loading'
              : unauthorized
                ? 'unauthorized'
                : accounts.isError
                  ? 'error'
                  : 'empty'
          }
          onCreate={() => openEditor('create')}
          onRetry={() => void accounts.refetch()}
        />
      ) : (
        <AccountList accounts={items} onArchive={openArchive} onEdit={openEditor} />
      )}

      {accounts.isSuccess && (totalPages > 1 || page > 1) ? (
        <AccountPagination onPageChange={setPage} page={page} totalPages={totalPages} />
      ) : null}
    </div>
  );
}
