import type { ReactNode } from 'react';
import styles from './DatedList.module.css';

interface DatedCellProps {
  children?: ReactNode;
  className?: string;
  /** Desktop column, counted from the label: 1, 2 or 3. */
  column: 1 | 2 | 3;
  title?: string;
}

/**
 * One secondary figure of a row. On a computer it has its own column, empty or not, so rows
 * stay aligned; below, the cells share the second line and an empty one disappears.
 */
export function DatedCell({ children, className, column, title }: DatedCellProps) {
  const empty = children === null || children === undefined || children === false;

  return (
    <span
      className={[styles.cell, className].filter(Boolean).join(' ')}
      data-cell={column}
      data-empty={empty || undefined}
      title={title}
    >
      {children}
    </span>
  );
}
