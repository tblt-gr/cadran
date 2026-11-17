import type { ReactNode } from 'react';
import styles from './RecurrenceRow.module.css';

interface RecurrenceRowProps {
  /** The row menu: every action of the row lives behind its kebab. */
  actions: ReactNode;
  /** Figures and badges on the right of the row. */
  aside?: ReactNode;
  /** Extra line under the meta, such as why a confidence is what it is. */
  note?: ReactNode;
  /** Figure on the title line, at its far end. */
  amount?: ReactNode;
  /** Bare glyph before the title, such as the category. */
  lead?: ReactNode;
  meta: ReactNode;
  title: ReactNode;
  /** Bare glyph after the title, such as the automatic creation. */
  titleEnd?: ReactNode;
}

/** One dense line of the recurrences lists: title and meta, figures, then the row menu. */
export function RecurrenceRow({
  actions,
  amount,
  aside,
  lead,
  meta,
  note,
  title,
  titleEnd,
}: RecurrenceRowProps) {
  return (
    <li className={styles.row} data-has-amount={amount ? true : undefined}>
      <div className={styles.main}>
        <h4 className={styles.title}>
          {lead}
          {title}
          {titleEnd}
        </h4>
        <p className={styles.meta}>{meta}</p>
        {note ? <p className={styles.note}>{note}</p> : null}
      </div>
      {amount ? <span className={styles.amountCell}>{amount}</span> : null}
      {aside ? <div className={styles.aside}>{aside}</div> : null}
      <div className={styles.actions} data-actions>
        {actions}
      </div>
    </li>
  );
}
