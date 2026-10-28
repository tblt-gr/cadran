import {
  archiveAccountGroup,
  createAccountGroup,
  updateAccountGroup,
  type AccountGroup,
  type CreateAccountGroupRequest,
  type UpdateAccountGroupRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { ArchiveGroupDialog } from '@/features/accounts/groups/archive-group-dialog/ArchiveGroupDialog';
import { GroupForm } from '@/features/accounts/groups/group-form/GroupForm';
import { GroupTree } from '@/features/accounts/groups/group-tree/GroupTree';
import { GroupTreeSkeleton } from '@/features/accounts/groups/group-tree/GroupTreeSkeleton';
import { listAllAccountGroups } from '@/features/accounts/groups/listAllAccountGroups';
import {
  groupErrorKind,
  GroupRequestError,
  groupRequestError,
} from '@/features/accounts/groups/groupError';
import { GroupsState } from '@/features/accounts/groups/groups-state/GroupsState';
import styles from './AccountGroupsPage.module.css';

type Editor = AccountGroup | 'create' | null;

export function AccountGroupsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<AccountGroup | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);

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
    queryKey: ['account-groups', includeArchived],
    queryFn: ({ signal }) => listAllAccountGroups(includeArchived, signal),
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
      await queryClient.invalidateQueries({ queryKey: ['monthly-recap'] });
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
      await queryClient.invalidateQueries({ queryKey: ['monthly-recap'] });
    },
    onError: (error) => {
      void refreshOnStaleState(error);
    },
  });

  const submitError = groupErrorKind(save.error, save.isError);
  const archiveError = groupErrorKind(archive.error, archive.isError);
  const unauthorized = groups.error instanceof GroupRequestError && groups.error.status === 401;
  const items = groups.data ?? [];
  const pagePending = groups.isPending && groups.data === undefined;

  return (
    <div className={styles.page}>
      <HeaderActions>
        <button
          aria-label={t('accountGroups.add')}
          className={`primary-action ${styles.add}`}
          onClick={() => openEditor('create')}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>{t('accountGroups.add')}</span>
        </button>
        <button
          aria-label={t('accountGroups.includeArchived')}
          aria-pressed={includeArchived}
          className="icon-button"
          onClick={() => setIncludeArchived((current) => !current)}
          title={t('accountGroups.includeArchived')}
          type="button"
        >
          <Icon name="archive" />
        </button>
      </HeaderActions>

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

      {pagePending ? (
        <GroupTreeSkeleton status={t('accountGroups.loading')} />
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
        <GroupTree groups={items} onArchive={setArchiving} onEdit={openEditor} />
      )}
    </div>
  );
}
