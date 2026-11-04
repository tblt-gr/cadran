import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './Chip.module.css';

interface ChipProps {
  label: ReactNode;
  onRemove: () => void;
  /** Accessible name of the whole chip, since `label` may be a skeleton block. */
  removeLabel: string;
}

/** A removable summary of one applied filter: the whole pill is the remove button. */
export function Chip({ label, onRemove, removeLabel }: ChipProps) {
  return (
    <button aria-label={removeLabel} className={styles.chip} onClick={onRemove} type="button">
      <span className={styles.label}>{label}</span>
      <Icon name="close" size={14} />
    </button>
  );
}
