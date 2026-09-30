import { useId, useState } from 'react';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import styles from './GoalHistory.module.css';

export function HistoryInfo({ label, text }: { label: string; text: string }) {
  const [open, setOpen] = useState(false);
  const id = useId();
  return (
    <span className={styles.help}>
      <InfoButton
        className={styles.infoButton}
        aria-controls={id}
        aria-expanded={open}
        label={label}
        onClick={() => setOpen(!open)}
      />
      <span className={styles.helpText} hidden={!open} id={id}>
        {text}
      </span>
    </span>
  );
}
