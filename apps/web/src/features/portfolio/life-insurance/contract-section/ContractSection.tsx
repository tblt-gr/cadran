import type { ReactNode } from 'react';
import styles from './ContractSection.module.css';

/**
 * One closed disclosure of the Contrat tab: its title and the date or state it speaks about stay
 * in the summary, the content and its action open below. It carries no plate of its own.
 */
export function ContractSection({
  title,
  hint,
  action,
  children,
  label,
  busy,
  testId,
}: {
  title: string;
  /** The date or state the section is about: a block while it loads. */
  hint: ReactNode;
  /** The section's main action, at the top of the opened content. */
  action?: ReactNode;
  children: ReactNode;
  label?: string;
  busy?: boolean;
  testId?: string;
}) {
  return (
    <details aria-busy={busy} aria-label={label} className={styles.section} data-testid={testId}>
      <summary className={styles.summary}>
        <h3>{title}</h3>
        <span className={styles.hint}>{hint}</span>
      </summary>
      <div className={styles.body}>
        {action ? <div className={styles.action}>{action}</div> : null}
        {children}
      </div>
    </details>
  );
}
