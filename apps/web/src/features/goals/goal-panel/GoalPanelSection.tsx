import { useId, type ReactNode } from 'react';
import styles from './GoalPanel.module.css';

/** A titled list of goal rows. */
export function GoalPanelSection({ title, children }: { title: string; children: ReactNode }) {
  const titleId = useId();
  return (
    <section aria-labelledby={titleId} className={styles.section}>
      <h3 id={titleId}>{title}</h3>
      <ul className={styles.rows}>{children}</ul>
    </section>
  );
}
