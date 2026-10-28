import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { buildTree } from '@/lib/tree/buildTree';
import { GroupTreeNode } from './GroupTreeNode';
import styles from './GroupTree.module.css';

interface GroupTreeProps {
  groups: AccountGroup[];
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

export function GroupTree({ groups, onArchive, onEdit }: GroupTreeProps) {
  const { t } = useTranslation();

  return (
    <ul aria-label={t('accountGroups.list.caption')} className={styles.branch}>
      {buildTree(groups).map((node) => (
        <GroupTreeNode key={node.item.id} node={node} onArchive={onArchive} onEdit={onEdit} />
      ))}
    </ul>
  );
}
