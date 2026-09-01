import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useDialogOverlay } from '@/hooks/use-dialog-overlay';
import styles from './ModalSheet.module.css';

interface ModalSheetProps {
  ariaLabel: string;
  children: ReactNode;
  close: () => void;
}

export function ModalSheet({ ariaLabel, children, close }: ModalSheetProps) {
  const sheet = useDialogOverlay(close);

  return createPortal(
    <div className={styles.backdrop} onMouseDown={close}>
      <section
        aria-label={ariaLabel}
        aria-modal="true"
        className={styles.sheet}
        onMouseDown={(event) => event.stopPropagation()}
        ref={sheet}
        role="dialog"
      >
        {children}
      </section>
    </div>,
    document.body,
  );
}
