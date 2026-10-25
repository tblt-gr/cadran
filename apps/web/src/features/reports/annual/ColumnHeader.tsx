import type { AnnualColumn } from '@cadran/api-client';
import styles from './ColumnHeader.module.css';

/** A column's label, with the institution of an account column below it as secondary text. */
export function ColumnHeader({ column }: { column: Pick<AnnualColumn, 'institution' | 'label'> }) {
  return (
    <>
      {column.label}
      {column.institution ? (
        <small className={styles.institution}>{column.institution}</small>
      ) : null}
    </>
  );
}
