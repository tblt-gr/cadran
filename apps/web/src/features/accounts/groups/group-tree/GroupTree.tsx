import type { AccountGroup } from '@cadran/api-client';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { buildTree } from '@/lib/tree/buildTree';
import { GroupTreeNode } from './GroupTreeNode';
import styles from './GroupTree.module.css';

interface GroupTreeProps {
  groups: AccountGroup[];
  moveDisabled?: boolean;
  /** Shows the move controls; they stay out of the way until the owner asks to reorder. */
  reordering?: boolean;
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
  /** Places the group before `beforeGroupId`, or last when null, among the displayed active siblings. */
  onMove?: (group: AccountGroup, beforeGroupId: string | null, siblingIds: string[]) => void;
}

export function GroupTree({
  groups,
  moveDisabled = false,
  reordering = false,
  onArchive,
  onEdit,
  onMove = () => undefined,
}: GroupTreeProps) {
  const { t } = useTranslation();
  const pendingFocus = useRef<{ id: string; direction: 'up' | 'down' } | null>(null);
  const order = groups.map((group) => group.id).join('|');

  // Once the write settles (saved or refused), hand the focus back to the control that was used: it
  // was disabled during the request and a moved row is re-inserted by the refetch.
  useEffect(() => {
    const target = pendingFocus.current;
    if (target === null || moveDisabled) {
      return;
    }
    const preferred = document.querySelector<HTMLButtonElement>(
      `[data-move="${target.id}:${target.direction}"]`,
    );
    const other = document.querySelector<HTMLButtonElement>(
      `[data-move="${target.id}:${target.direction === 'up' ? 'down' : 'up'}"]`,
    );
    (preferred && !preferred.disabled ? preferred : other)?.focus();
    pendingFocus.current = null;
  }, [order, moveDisabled]);

  function move(
    group: AccountGroup,
    direction: 'up' | 'down',
    beforeGroupId: string | null,
    siblingIds: string[],
  ) {
    pendingFocus.current = { id: group.id, direction };
    onMove(group, beforeGroupId, siblingIds);
  }

  const roots = buildTree(groups);

  return (
    <ul aria-label={t('accountGroups.list.caption')} className={styles.branch}>
      {roots.map((node) => (
        <GroupTreeNode
          key={node.item.id}
          moveDisabled={moveDisabled}
          node={node}
          onArchive={onArchive}
          onEdit={onEdit}
          onMove={move}
          reordering={reordering}
          siblings={roots}
        />
      ))}
    </ul>
  );
}
