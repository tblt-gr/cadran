import {
  archiveAccount,
  createAccount,
  listAccounts,
  recordAccountBalance,
  recordAccountRuleOverride,
  updateAccount,
  withdrawAccountRuleOverride,
  type Account,
  type AccountRuleClaim,
  type CreateAccountRequest,
  type RecordAccountBalanceRequest,
  type RecordAccountRuleOverrideRequest,
  type UpdateAccountRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import {
  accountErrorKind,
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from './accountError';
import { AccountFilters } from './account-filters/AccountFilters';
import { AccountEditor } from './account-editor/AccountEditor';
import { AccountList } from './account-list/AccountList';
import { AccountPagination } from './account-pagination/AccountPagination';
import { AccountRuleOverrideForm } from './account-rules/account-rule-override-form/AccountRuleOverrideForm';
import { AccountRulesPanel } from './account-rules/AccountRulesPanel';
import type { OverrideDraft } from './account-rules/overrideTarget';
import { WithdrawOverrideDialog } from './account-rules/withdraw-override-dialog/WithdrawOverrideDialog';
import { AccountWizard } from './account-wizard/AccountWizard';
import { AccountsState } from './accounts-state/AccountsState';
import { ArchiveAccountDialog } from './archive-account-dialog/ArchiveAccountDialog';
import { RecordBalanceForm } from './record-balance-form/RecordBalanceForm';
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
  const [inspecting, setInspecting] = useState<Account | null>(null);
  // Recording and withdrawing a claim both happen for the account whose rules
  // are open, and both replace that modal rather than stacking a second one
  // over it. Closing either returns the reader to the rules they came from.
  const [overriding, setOverriding] = useState<OverrideDraft | null>(null);
  const [withdrawing, setWithdrawing] = useState<AccountRuleClaim | null>(null);
  const [recording, setRecording] = useState<Account | null>(null);
  const [saved, setSaved] = useState<
    'saved' | 'archived' | 'claimed' | 'withdrawn' | 'recorded' | null
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

  const recordOverride = useMutation({
    mutationFn: async ({
      account,
      body,
    }: {
      account: Account;
      body: RecordAccountRuleOverrideRequest;
    }) => {
      const result = await withCsrfRetry(() =>
        recordAccountRuleOverride({ ...authApiOptions(), path: { id: account.id }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setOverriding(null);
      setSaved('claimed');
      await refreshRules();
    },
  });

  const recordBalance = useMutation({
    mutationFn: async ({
      account,
      body,
    }: {
      account: Account;
      body: RecordAccountBalanceRequest;
    }) => {
      const result = await withCsrfRetry(() =>
        recordAccountBalance({ ...authApiOptions(), path: { id: account.id }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      setRecording(null);
      setSaved('recorded');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  const withdrawOverride = useMutation({
    mutationFn: async ({ account, overrideId }: { account: Account; overrideId: string }) => {
      const result = await withCsrfRetry(() =>
        withdrawAccountRuleOverride({
          ...authApiOptions(),
          path: { id: account.id, overrideId },
          body: {},
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setWithdrawing(null);
      setSaved('withdrawn');
      await refreshRules();
    },
  });

  /**
   * A claim changes what every business date resolves to, and each date is its
   * own cache key. Invalidating the whole family is the only way a reader who
   * moves the date back afterwards sees the corrected answer rather than the
   * one cached before the claim existed.
   */
  async function refreshRules() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['account-rules'] }),
      queryClient.invalidateQueries({ queryKey: ['account-rule-overrides'] }),
    ]);
  }

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function closeOverride() {
    setOverriding(null);
    recordOverride.reset();
  }

  function closeWithdraw() {
    setWithdrawing(null);
    withdrawOverride.reset();
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

      {editor ? (
        <Modal
          close={closeEditor}
          eyebrow={t(
            editor === 'create' ? 'accounts.form.createEyebrow' : 'accounts.form.editEyebrow',
          )}
          title={t(editor === 'create' ? 'accounts.form.createTitle' : 'accounts.form.editTitle')}
        >
          {editor === 'create' ? (
            <AccountWizard
              onCancel={closeEditor}
              onCreate={(body) => save.mutate(body)}
              pending={save.isPending}
              submitError={accountErrorKind(save.error, save.isError)}
            />
          ) : (
            <AccountEditor
              account={editor}
              key={editor.id}
              onCancel={closeEditor}
              onSubmit={(body) => save.mutate(body)}
              pending={save.isPending}
              submitError={accountErrorKind(save.error, save.isError)}
            />
          )}
        </Modal>
      ) : null}

      {inspecting && !overriding && !withdrawing ? (
        <Modal
          close={() => setInspecting(null)}
          eyebrow={t('accounts.rules.eyebrow')}
          title={t('accounts.rules.title', { label: inspecting.label })}
        >
          <AccountRulesPanel
            account={inspecting}
            key={inspecting.id}
            onOverride={setOverriding}
            onWithdraw={setWithdrawing}
          />
        </Modal>
      ) : null}

      {inspecting && overriding ? (
        <Modal
          close={closeOverride}
          eyebrow={t('accounts.overrides.eyebrow')}
          title={t('accounts.overrides.title', { label: inspecting.label })}
        >
          <AccountRuleOverrideForm
            accountAsset={inspecting.assetCode}
            initialKind={overriding.kind}
            kinds={overriding.kinds}
            onCancel={closeOverride}
            onSubmit={(body) => recordOverride.mutate({ account: inspecting, body })}
            pending={recordOverride.isPending}
            submitError={accountErrorKind(recordOverride.error, recordOverride.isError)}
          />
        </Modal>
      ) : null}

      {inspecting && withdrawing ? (
        <Modal
          close={closeWithdraw}
          eyebrow={t('accounts.overrides.eyebrow')}
          title={t('accounts.overrides.withdrawTitle')}
        >
          <WithdrawOverrideDialog
            onCancel={closeWithdraw}
            onConfirm={() =>
              withdrawOverride.mutate({
                account: inspecting,
                overrideId: withdrawing.overrideId,
              })
            }
            pending={withdrawOverride.isPending}
            reason={withdrawing.reason}
            submitError={accountErrorKind(withdrawOverride.error, withdrawOverride.isError)}
          />
        </Modal>
      ) : null}

      {recording ? (
        <Modal
          close={closeRecording}
          eyebrow={t('accounts.balances.eyebrow')}
          title={t('accounts.balances.title', { label: recording.label })}
        >
          <RecordBalanceForm
            account={recording}
            key={recording.id}
            onCancel={closeRecording}
            onSubmit={(body) => recordBalance.mutate({ account: recording, body })}
            pending={recordBalance.isPending}
            submitError={accountErrorKind(recordBalance.error, recordBalance.isError)}
            valuation={recording.valuation}
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
        <AccountList
          accounts={items}
          onArchive={openArchive}
          onEdit={openEditor}
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
