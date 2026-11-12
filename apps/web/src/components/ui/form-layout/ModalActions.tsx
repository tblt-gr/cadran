import type { ReactNode } from 'react';
import styles from './FormLayout.module.css';

/** Right-aligned footer: the optional secondary action first, the primary action last. */
export function ModalActions({ children }: { children: ReactNode }) {
  return (
    <div className={styles.actions} data-modal-actions>
      {children}
    </div>
  );
}
