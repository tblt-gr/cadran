import type { ReactNode } from 'react';
import styles from './RecurrenceRow.module.css';

interface RecurrenceRowProps {
  /** The row menu: every action of the row lives behind its kebab. */
  actions: ReactNode;
  /** Figures and badges on the right of the row. */
  aside?: ReactNode;
  /** Extra line under the meta, such as why a confidence is what it is. */
  note?: ReactNode;
  meta: ReactNode;
  title: ReactNode;
}

/** One dense line of the recurrences lists: title and meta, figures, then the row menu. */
export function RecurrenceRow({ actions, aside, meta, note, title }: RecurrenceRowProps) {
  return (
    <li className={styles.row}>
      <div className={styles.main}>
        <h4 className={styles.title}>{title}</h4>
        <p className={styles.meta}>{meta}</p>
        {note ? <p className={styles.note}>{note}</p> : null}
      </div>
      {aside ? <div className={styles.aside}>{aside}</div> : null}
      <div className={styles.actions}>{actions}</div>
    </li>
  );
}
