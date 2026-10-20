import type { ReactNode } from 'react';
import styles from './SheetRow.module.css';

/** The account sheet: one list of label and value lines, split by local 1 px rules. */
export function Sheet({ children }: { children: ReactNode }) {
  return <dl className={styles.sheet}>{children}</dl>;
}

interface SheetRowProps {
  /** The line's actions, at its end. */
  actions?: ReactNode;
  /** A short sentence under the value; the line's warning, with its icon. */
  alert?: ReactNode;
  /** Folded content under the whole line, such as the rule's history. */
  below?: ReactNode;
  label: ReactNode;
  /** "Depuis le X" or "Du X au Y", in small muted text under the value. */
  since?: ReactNode;
  value: ReactNode;
}

/** One line of the sheet: label on the left, value in bold tabular figures with its start date under it. */
export function SheetRow({ actions, alert, below, label, since, value }: SheetRowProps) {
  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd className={styles.value}>
        <div className={styles.figure}>{value}</div>
        {since ? <small>{since}</small> : null}
        {alert}
      </dd>
      <dd className={styles.actions}>{actions}</dd>
      {below ? <dd className={styles.below}>{below}</dd> : null}
    </div>
  );
}
