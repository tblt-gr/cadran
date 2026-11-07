import { Icon } from '@/components/ui/icon/Icon';
import styles from './HoldingsTable.module.css';

interface SortableHeaderProps {
  label: string;
  /** Null when this column is not the current order. */
  direction: 'ascending' | 'descending' | null;
  /** The order the server applies when this header is used. */
  appliedDirection: 'ascending' | 'descending';
  disabled: boolean;
  onSort: () => void;
}

/** A column header that asks the server for another order; the browser never sorts amounts. */
export function SortableHeader({
  label,
  direction,
  appliedDirection,
  disabled,
  onSort,
}: SortableHeaderProps) {
  return (
    <th aria-sort={direction ?? undefined} scope="col">
      <button className={styles.sort} disabled={disabled} onClick={onSort} type="button">
        {label}
        <Icon
          name={(direction ?? appliedDirection) === 'ascending' ? 'arrow-up' : 'arrow-down'}
          size={12}
        />
      </button>
    </th>
  );
}
