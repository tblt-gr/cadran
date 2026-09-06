import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import type { SortDirection } from '@/lib/tableSort';
import styles from './SortableHeader.module.css';

interface SortableHeaderProps {
  children: ReactNode;
  column: string;
  current: string;
  direction: SortDirection;
  onSort: (column: string) => void;
}

export function SortableHeader({
  children,
  column,
  current,
  direction,
  onSort,
}: SortableHeaderProps) {
  const { t } = useTranslation();
  const active = current === column;

  return (
    <th
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      scope="col"
    >
      <button
        aria-label={t('accountGroups.list.sortBy', {
          column: typeof children === 'string' ? children : column,
        })}
        className={styles.button}
        onClick={() => onSort(column)}
        type="button"
      >
        <span>{children}</span>
        {active ? <Icon name={direction === 'asc' ? 'arrow-up' : 'arrow-down'} size={12} /> : null}
      </button>
    </th>
  );
}
