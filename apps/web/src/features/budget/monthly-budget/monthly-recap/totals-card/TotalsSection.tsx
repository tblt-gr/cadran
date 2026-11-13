import { useState, type ReactNode } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { GoalColumnHead } from '@/features/goals/goal-mark/GoalMark';
import styles from './TotalsCard.module.css';

/** One foldable group of the flow summary; open by default, its goal column head in the summary while it is open. */
export function TotalsSection({
  children,
  goalHead,
  title,
}: {
  children: ReactNode;
  goalHead: boolean;
  title: string;
}) {
  const [open, setOpen] = useState(true);
  return (
    <div className={styles.section}>
      <Disclosure
        compact
        meta={goalHead && open ? <GoalColumnHead /> : undefined}
        onToggle={setOpen}
        open={open}
        spread
        title={title}
      >
        <ul className={styles.rows}>{children}</ul>
      </Disclosure>
    </div>
  );
}
