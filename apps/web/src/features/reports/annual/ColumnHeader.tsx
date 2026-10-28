import type { AnnualColumn } from '@cadran/api-client';
import { useAnnualColumnLabel } from './columnLabel';
import styles from './ColumnHeader.module.css';

/**
 * A column's title, with the institution of an account column or the parent of a sub-group as
 * secondary text. The parent goes below the title in report headers and stays on the title line
 * where `inlineParent` is set (column picker and selection list).
 */
export function ColumnHeader({
  column,
  inlineParent = false,
}: {
  column: Pick<AnnualColumn, 'id' | 'institution' | 'label' | 'parentLabel'>;
  inlineParent?: boolean;
}) {
  const labelOf = useAnnualColumnLabel();

  return (
    <>
      {labelOf(column)}
      {column.institution ? (
        <small className={styles.institution}>{column.institution}</small>
      ) : null}
      {column.parentLabel ? (
        <>
          {inlineParent ? ' ' : null}
          <small className={inlineParent ? styles.parentInline : styles.institution}>
            ({column.parentLabel})
          </small>
        </>
      ) : null}
    </>
  );
}
