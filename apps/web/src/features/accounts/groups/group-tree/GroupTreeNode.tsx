import type { AccountGroup } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { canonicalGroupColor } from '@/features/accounts/group-color';
import type { TreeNode } from '@/lib/tree/buildTree';
import { GroupTreeActions } from './GroupTreeActions';
import styles from './GroupTree.module.css';

interface GroupTreeNodeProps {
  node: TreeNode<AccountGroup>;
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
  moveDisabled: boolean;
  onMove: (
    group: AccountGroup,
    direction: 'up' | 'down',
    beforeGroupId: string | null,
    siblingIds: string[],
  ) => void;
  reordering: boolean;
  /** The nodes sharing this node's parent, in saved order. */
  siblings: TreeNode<AccountGroup>[];
}

export function GroupTreeNode({
  node,
  moveDisabled,
  onArchive,
  onEdit,
  onMove,
  reordering,
  siblings,
}: GroupTreeNodeProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const childrenId = useId();
  const { item: group, children } = node;
  const archived = group.archivedAt !== null;
  // Archived siblings stay hidden from the order the server compares and keep their place.
  const active = siblings.map((sibling) => sibling.item).filter((item) => item.archivedAt === null);
  const index = active.findIndex((item) => item.id === group.id);
  const siblingIds = active.map((item) => item.id);

  return (
    <li className={styles.node}>
      <div
        className={styles.row}
        data-archived={archived}
        data-marker
        style={{ borderInlineStartColor: canonicalGroupColor(group.resolvedColor) ?? undefined }}
      >
        {children.length > 0 ? (
          <button
            aria-controls={childrenId}
            aria-expanded={expanded}
            aria-label={t(expanded ? 'accountGroups.tree.collapse' : 'accountGroups.tree.expand', {
              count: children.length,
              label: group.label,
            })}
            className={`icon-ghost ${styles.toggle}`}
            data-expanded={expanded}
            onClick={() => setExpanded((current) => !current)}
            type="button"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ) : (
          <span aria-hidden="true" className={styles.toggleSpacer} />
        )}
        <div className={styles.summary}>
          <p className={styles.identity}>
            <span>{group.label}</span>
          </p>
          <p className={styles.meta}>
            <span className="sr-only">{t('accountGroups.tree.level', { depth: group.depth })}</span>
            {children.length > 0 ? (
              <span>{t('accountGroups.tree.childCount', { count: children.length })}</span>
            ) : null}
            {archived ? <span>{t('accountGroups.list.archived')}</span> : null}
          </p>
        </div>
        {reordering && !archived ? (
          <>
            <button
              aria-label={t('accountGroups.reorder.moveUp', { label: group.label })}
              className={`icon-ghost ${styles.moveButton}`}
              data-move={`${group.id}:up`}
              disabled={moveDisabled || index <= 0}
              onClick={() => onMove(group, 'up', active[index - 1]!.id, siblingIds)}
              title={t('accountGroups.reorder.moveUp', { label: group.label })}
              type="button"
            >
              <Icon name="arrow-up" />
            </button>
            <button
              aria-label={t('accountGroups.reorder.moveDown', { label: group.label })}
              className={`icon-ghost ${styles.moveButton}`}
              data-move={`${group.id}:down`}
              disabled={moveDisabled || index < 0 || index >= active.length - 1}
              onClick={() => onMove(group, 'down', active[index + 2]?.id ?? null, siblingIds)}
              title={t('accountGroups.reorder.moveDown', { label: group.label })}
              type="button"
            >
              <Icon name="arrow-down" />
            </button>
          </>
        ) : null}
        <GroupTreeActions group={group} onArchive={onArchive} onEdit={onEdit} />
      </div>
      {children.length > 0 ? (
        <ul className={styles.branch} hidden={!expanded} id={childrenId}>
          {expanded
            ? children.map((child) => (
                <GroupTreeNode
                  key={child.item.id}
                  moveDisabled={moveDisabled}
                  node={child}
                  onArchive={onArchive}
                  onEdit={onEdit}
                  onMove={onMove}
                  reordering={reordering}
                  siblings={children}
                />
              ))
            : null}
        </ul>
      ) : null}
    </li>
  );
}
