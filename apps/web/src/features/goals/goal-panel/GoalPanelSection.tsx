import { useId, type ReactNode } from 'react';
import {
  GoalSelectionToggle,
  GoalSelectionToolbar,
} from '@/features/goals/bulk-selection/GoalSelectionControls';
import styles from './GoalPanel.module.css';

/** A titled list of goal rows. */
export function GoalPanelSection({ title, children }: { title: string; children: ReactNode }) {
  const titleId = useId();
  return (
    <section aria-labelledby={titleId} className={styles.section}>
      <div className={styles.sectionHead}>
        <h3 id={titleId}>{title}</h3>
        <GoalSelectionToggle />
      </div>
      <GoalSelectionToolbar />
      <ul className={styles.rows}>{children}</ul>
    </section>
  );
}
