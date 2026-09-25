import { listAccounts, type Account } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountRequestError, requestFailed, accountRequestError } from './accountError';
import { AccountsPageModals } from './accounts-page-modals/AccountsPageModals';
import { AccountFilters } from './account-filters/AccountFilters';
import { AccountList } from './account-list/AccountList';
import { AccountPagination } from './account-pagination/AccountPagination';
import { AccountsState } from './accounts-state/AccountsState';
import { useAccountsPageMutations } from './useAccountsPageMutations';
import styles from './AccountsPage.module.css';

const PAGE_SIZE = 50;

type Editor = Account | 'create' | null;

export function AccountsPage() {
  const { t } = useTranslation();
  const [includeClosed, setIncludeClosed] = useState(false);
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<Account | null>(null);
  const [inspecting, setInspecting] = useState<Account | null>(null);
  const [recording, setRecording] = useState<Account | null>(null);
  const [reconciling, setReconciling] = useState<Account | null>(null);
  const [saved, setSaved] = useState<
    'saved' | 'archived' | 'claimed' | 'withdrawn' | 'recorded' | 'reconciled' | null
  >(null);

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

  const { save, archive, recordBalance } = useAccountsPageMutations({
    editor,
    closeEditor: () => setEditor(null),
    closeArchive: () => setArchiving(null),
    closeRecording: () => setRecording(null),
    notify: setSaved,
  });

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function closeArchive() {
    setArchiving(null);
    archive.reset();
  }

  function closeRecording() {
    setRecording(null);
    recordBalance.reset();
  }

  function openRecording(account: Account) {
    setSaved(null);
    recordBalance.reset();
    setRecording(account);
  }

  function openReconciling(account: Account) {
    setSaved(null);
    setReconciling(account);
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
  // The modal must read the live row: a stale version would fail every retry.
  const reconcilingLive = reconciling
    ? (items.find((item) => item.id === reconciling.id) ?? reconciling)
    : null;
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
        <div className={styles.introActions}>
          <a
            className="secondary-action"
            href="/accounts/groups"
            onClick={(event) => handleClientNavigation(event, '/accounts/groups')}
          >
            {t('accounts.manageGroups')}
          </a>
          <button className="primary-action" onClick={() => openEditor('create')} type="button">
            {t('accounts.add')}
          </button>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accounts.toasts.${saved}`)}</Toast>
      ) : null}

      <AccountsPageModals
        archive={archive}
        archiving={archiving}
        closeArchive={closeArchive}
        closeEditor={closeEditor}
        closeRecording={closeRecording}
        editor={editor}
        inspecting={inspecting}
        onOverrideRecorded={() => setSaved('claimed')}
        onOverrideWithdrawn={() => setSaved('withdrawn')}
        onReconciled={() => {
          setReconciling(null);
          setSaved('reconciled');
        }}
        reconciling={reconciling}
        reconcilingLive={reconcilingLive}
        recordBalance={recordBalance}
        recording={recording}
        save={save}
        setInspecting={setInspecting}
        setReconciling={setReconciling}
      />

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
        <AccountList
          accounts={items}
          onArchive={openArchive}
          onEdit={openEditor}
          onReconcile={openReconciling}
          onRecordBalance={openRecording}
          onRules={setInspecting}
        />
      )}

      {accounts.isSuccess && (totalPages > 1 || page > 1) ? (
        <AccountPagination onPageChange={setPage} page={page} totalPages={totalPages} />
      ) : null}
    </div>
  );
}
