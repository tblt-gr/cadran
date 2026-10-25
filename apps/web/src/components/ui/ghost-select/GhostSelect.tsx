import type { SelectHTMLAttributes } from 'react';
import styles from './GhostSelect.module.css';

/** A select without background or border, for quiet period pickers in page toolbars. */
export function GhostSelect({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={`${styles.select} ${className ?? ''}`.trim()} />;
}
