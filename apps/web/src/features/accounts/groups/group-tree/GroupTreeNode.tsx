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
}

export function GroupTreeNode({ node, onArchive, onEdit }: GroupTreeNodeProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const childrenId = useId();
  const { item: group, children } = node;
  const archived = group.archivedAt !== null;

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
        <GroupTreeActions group={group} onArchive={onArchive} onEdit={onEdit} />
      </div>
      {children.length > 0 ? (
        <ul className={styles.branch} hidden={!expanded} id={childrenId}>
          {expanded
            ? children.map((child) => (
                <GroupTreeNode
                  key={child.item.id}
                  node={child}
                  onArchive={onArchive}
                  onEdit={onEdit}
                />
              ))
            : null}
        </ul>
      ) : null}
    </li>
  );
}
