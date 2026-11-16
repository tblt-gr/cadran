import type { AccountGroup } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { ArchiveGroupDialog } from '@/features/accounts/groups/archive-group-dialog/ArchiveGroupDialog';
import { GroupMoveStatus } from '@/features/accounts/groups/group-move-status/GroupMoveStatus';
import { GroupForm } from '@/features/accounts/groups/group-form/GroupForm';
import { GroupPageActions } from '@/features/accounts/groups/group-page-actions/GroupPageActions';
import { GroupTree } from '@/features/accounts/groups/group-tree/GroupTree';
import { GroupTreeSkeleton } from '@/features/accounts/groups/group-tree/GroupTreeSkeleton';
import { listAllAccountGroups } from '@/features/accounts/groups/listAllAccountGroups';
import { groupErrorKind, GroupRequestError } from '@/features/accounts/groups/groupError';
import { GroupsState } from '@/features/accounts/groups/groups-state/GroupsState';
import { useAccountGroupWrites } from '@/features/accounts/groups/useAccountGroupWrites';
import {
  useMoveAccountGroup,
  type GroupMove,
} from '@/features/accounts/groups/useMoveAccountGroup';
import styles from './AccountGroupsPage.module.css';

type Editor = AccountGroup | 'create' | null;

export function AccountGroupsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<AccountGroup | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);
  const [reordering, setReordering] = useState(false);
  const [lastMove, setLastMove] = useState<GroupMove | null>(null);
  const [announcement, setAnnouncement] = useState<string | null>(null);

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

  const { save, archive } = useAccountGroupWrites({
    editor,
    onSaved: () => {
      setEditor(null);
      setSaved('saved');
    },
    onArchived: () => {
      setArchiving(null);
      setSaved('archived');
    },
  });

  const move = useMoveAccountGroup((group, moved) =>
    setAnnouncement(
      t('accountGroups.reorder.moved', {
        count: moved.siblingIds.length,
        label: group.label,
        rank: moved.rank,
      }),
    ),
  );

  function requestMove(group: AccountGroup, beforeGroupId: string | null, siblingIds: string[]) {
    const next = { group, beforeGroupId, expectedSiblingIds: siblingIds };
    setLastMove(next);
    setAnnouncement(null);
    move.mutate(next);
  }

  function reloadOrder() {
    move.reset();
    void queryClient.invalidateQueries({ queryKey: ['account-groups'] });
  }

  const moveFailure = !move.isError
    ? null
    : groupErrorKind(move.error, true) === 'staleOrder'
      ? 'stale'
      : 'failed';

  const submitError = groupErrorKind(save.error, save.isError);
  const archiveError = groupErrorKind(archive.error, archive.isError);
  const unauthorized =
    groups.error instanceof GroupRequestError && groups.error.kind === 'unauthorized';
  const items = groups.data ?? [];
  const pagePending = groups.isPending && groups.data === undefined;

  return (
    <div className={styles.page}>
      <GroupPageActions
        includeArchived={includeArchived}
        onAdd={() => openEditor('create')}
        onToggleArchived={() => setIncludeArchived((current) => !current)}
        onToggleReordering={() => setReordering((current) => !current)}
        reordering={reordering}
      />

      {reordering ? (
        <p className={styles.reorderHint} role="status">
          <Icon name="reorder" />
          {t('accountGroups.reorder.hint')}
        </p>
      ) : null}
      {pagePending ? null : (
        <GroupMoveStatus
          announcement={announcement}
          failure={moveFailure}
          onReload={reloadOrder}
          onRetry={lastMove ? () => move.mutate(lastMove) : null}
        />
      )}

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accountGroups.toasts.${saved}`)}</Toast>
      ) : null}

      {editor ? (
        <Modal
          intent={editor === 'create' ? 'create' : 'edit'}
          close={closeEditor}

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
          intent="archive"
          close={() => {
            setArchiving(null);
            archive.reset();
          }}

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
        <GroupTree
          groups={items}
          moveDisabled={move.isPending}
          onArchive={setArchiving}
          onEdit={openEditor}
          onMove={requestMove}
          reordering={reordering}
        />
      )}
    </div>
  );
}
