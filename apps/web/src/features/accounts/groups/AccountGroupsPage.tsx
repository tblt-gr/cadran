import {
  archiveAccountGroup,
  createAccountGroup,
  listAccountGroups,
  listAccounts,
  updateAccountGroup,
  type AccountGroup,
  type CreateAccountGroupRequest,
  type UpdateAccountGroupRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { ArchiveGroupDialog } from '@/features/accounts/groups/archive-group-dialog/ArchiveGroupDialog';
import { GroupForm } from '@/features/accounts/groups/group-form/GroupForm';
import { GroupList } from '@/features/accounts/groups/group-list/GroupList';
import {
  AllocationCharts,
  type ChartView,
} from '@/features/dashboard/allocation-charts/AllocationCharts';
import { AllocationLegend } from '@/features/dashboard/allocation-panel/AllocationLegend';
import {
  groupErrorKind,
  GroupRequestError,
  groupRequestError,
} from '@/features/accounts/groups/groupError';
import { GroupsState } from '@/features/accounts/groups/groups-state/GroupsState';
import { useNetWorth } from '@/features/dashboard/net-worth/useNetWorth';
import styles from './AccountGroupsPage.module.css';

const ACCOUNT_PAGE_SIZE = 100;

type Editor = AccountGroup | 'create' | null;

export function AccountGroupsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<AccountGroup | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);
  const [allocationView, setAllocationView] = useState<ChartView>('treemap');

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    save.reset();
    setEditor(target);
  }

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  const groups = useQuery({
    queryKey: ['account-groups', includeArchived, page],
    queryFn: async ({ signal }) => {
      const result = await listAccountGroups({
        ...authApiOptions(),
        query: { includeArchived, page, perPage: 50 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  const netWorth = useNetWorth();
  const accounts = useQuery({
    queryKey: ['accounts', 'for-groups'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: true, page: 1, perPage: ACCOUNT_PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  async function refreshOnStaleState(error: unknown) {
    if (error instanceof GroupRequestError && error.kind === 'stale') {
      await queryClient.invalidateQueries({ queryKey: ['account-groups'] });
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateAccountGroupRequest | UpdateAccountGroupRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateAccountGroup({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateAccountGroupRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createAccountGroup({ ...authApiOptions(), body: body as CreateAccountGroupRequest }),
            );

      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved('saved');
      await queryClient.invalidateQueries({ queryKey: ['account-groups'] });
      await queryClient.invalidateQueries({ queryKey: ['net-worth'] });
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
    onError: (error) => {
      void refreshOnStaleState(error);
    },
  });

  const archive = useMutation({
    mutationFn: async (group: AccountGroup) => {
      const result = await withCsrfRetry(() =>
        archiveAccountGroup({
          ...authApiOptions(),
          path: { id: group.id },
          body: { version: group.version },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setArchiving(null);
      setSaved('archived');
      await queryClient.invalidateQueries({ queryKey: ['account-groups'] });
      await queryClient.invalidateQueries({ queryKey: ['net-worth'] });
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
    onError: (error) => {
      void refreshOnStaleState(error);
    },
  });

  const submitError = groupErrorKind(save.error, save.isError);
  const archiveError = groupErrorKind(archive.error, archive.isError);
  const unauthorized = groups.error instanceof GroupRequestError && groups.error.status === 401;
  const items = groups.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((groups.data?.total ?? 0) / 50));
  const allocationRoots = (netWorth.data?.allocation ?? []).filter((entry) => entry.depth === 1);

  if (groups.data && page > totalPages) {
    setPage(totalPages);
  }

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="account-groups-intro-title">
        <div>
          <p>{t('accountGroups.eyebrow')}</p>
          <h2 id="account-groups-intro-title">{t('accountGroups.title')}</h2>
          <span>{t('accountGroups.description')}</span>
        </div>
        <div className={styles.introActions}>
          <a
            className="secondary-action"
            href="/accounts"
            onClick={(event) => handleClientNavigation(event, '/accounts')}
          >
            {t('accountGroups.backToAccounts')}
          </a>
          <button className="primary-action" onClick={() => openEditor('create')} type="button">
            {t('accountGroups.add')}
          </button>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accountGroups.toasts.${saved}`)}</Toast>
      ) : null}

      {editor ? (
        <Modal
          close={closeEditor}
          eyebrow={t(
            editor === 'create'
              ? 'accountGroups.form.createEyebrow'
              : 'accountGroups.form.editEyebrow',
          )}
          title={t(
            editor === 'create' ? 'accountGroups.form.createTitle' : 'accountGroups.form.editTitle',
          )}
        >
          <GroupForm
            group={editor === 'create' ? undefined : editor}
            key={editor === 'create' ? 'create' : editor.id}
            onCancel={closeEditor}
            onSubmit={(body) => save.mutate(body)}
            pending={save.isPending}
            submitError={submitError}
          />
        </Modal>
      ) : null}

      {archiving ? (
        <Modal
          close={() => {
            setArchiving(null);
            archive.reset();
          }}
          eyebrow={t('accountGroups.archive.eyebrow')}
          title={t('accountGroups.archive.title')}
        >
          <ArchiveGroupDialog
            group={archiving}
            onCancel={() => {
              setArchiving(null);
              archive.reset();
            }}
            onConfirm={() => archive.mutate(archiving)}
            pending={archive.isPending}
            submitError={archiveError}
          />
        </Modal>
      ) : null}

      <div className={styles.toolbar}>
        <label>
          <input
            checked={includeArchived}
            onChange={(event) => {
              setIncludeArchived(event.target.checked);
              setPage(1);
            }}
            type="checkbox"
          />
          <span>{t('accountGroups.includeArchived')}</span>
        </label>
      </div>

      {groups.isPending ? (
        <GroupsState
          includeArchived={includeArchived}
          kind="loading"
          onCreate={() => openEditor('create')}
          onRetry={() => void groups.refetch()}
        />
      ) : groups.isError ? (
        <GroupsState
          includeArchived={includeArchived}
          kind={unauthorized ? 'unauthorized' : 'error'}
          onCreate={() => openEditor('create')}
          onRetry={() => void groups.refetch()}
        />
      ) : items.length === 0 ? (
        <GroupsState
          includeArchived={includeArchived}
          kind="empty"
          onCreate={() => openEditor('create')}
          onRetry={() => void groups.refetch()}
        />
      ) : (
        <>
          {netWorth.isPending ? (
            <section className={`card ${styles.allocation}`} aria-busy="true" role="status">
              {t('accountGroups.list.allocationLoading')}
            </section>
          ) : netWorth.isError ? (
            <section className={`card ${styles.allocation}`} role="alert">
              {t('accountGroups.list.allocationUnavailable')}
            </section>
          ) : allocationRoots.length > 0 ? (
            <section className={`card ${styles.allocation}`} data-allocation-view={allocationView}>
              <div className={allocationView === 'pie' ? styles.pieLayout : undefined}>
                <AllocationCharts
                  allocation={netWorth.data?.allocation ?? []}
                  onViewChange={setAllocationView}
                  reason={netWorth.data?.reason ?? null}
                  total={netWorth.data?.total ?? null}
                />
                {allocationView === 'pie' ? (
                  <AllocationLegend
                    entries={allocationRoots}
                    reason={netWorth.data?.reason ?? null}
                  />
                ) : null}
              </div>
            </section>
          ) : null}
          <GroupList
            accounts={accounts.data?.items ?? []}
            accountsStatus={accounts.isPending ? 'pending' : accounts.isError ? 'error' : 'ready'}
            allocation={netWorth.data?.allocation ?? []}
            allocationStatus={netWorth.isPending ? 'pending' : netWorth.isError ? 'error' : 'ready'}
            contributions={netWorth.data?.contributions ?? []}
            groups={items}
            netWorthReason={netWorth.data?.reason ?? null}
            onArchive={setArchiving}
            onEdit={openEditor}
          />
        </>
      )}

      {groups.isSuccess && (totalPages > 1 || page > 1) ? (
        <nav className={styles.pagination} aria-label={t('accountGroups.pagination.label')}>
          <button
            className="secondary-action"
            disabled={page === 1}
            onClick={() => setPage((current) => current - 1)}
            type="button"
          >
            {t('accountGroups.pagination.previous')}
          </button>
          <span>{t('accountGroups.pagination.position', { page, total: totalPages })}</span>
          <button
            className="secondary-action"
            disabled={page >= totalPages}
            onClick={() => setPage((current) => current + 1)}
            type="button"
          >
            {t('accountGroups.pagination.next')}
          </button>
        </nav>
      ) : null}
    </div>
  );
}
