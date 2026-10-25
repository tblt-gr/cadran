import type { AnnualColumn } from '@cadran/api-client';
import { useAnnualColumnLabel } from './columnLabel';
import styles from './ColumnHeader.module.css';

/** A column's title, with the institution of an account column below it as secondary text. */
export function ColumnHeader({
  column,
}: {
  column: Pick<AnnualColumn, 'id' | 'institution' | 'label'>;
}) {
  const labelOf = useAnnualColumnLabel();

  return (
    <>
      {labelOf(column)}
      {column.institution ? (
        <small className={styles.institution}>{column.institution}</small>
      ) : null}
    </>
  );
}
