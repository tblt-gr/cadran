import { useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import styles from './ModalSheet.module.css';

interface ModalSheetProps {
  ariaLabel: string;
  children: ReactNode;
  close: () => void;
}

export function ModalSheet({ ariaLabel, children, close }: ModalSheetProps) {
  const sheet = useRef<HTMLElement>(null);

  useEffect(() => {
    const appShell = document.querySelector<HTMLElement>('[data-app-shell]');
    const previousOverflow = document.body.style.overflow;
    appShell?.setAttribute('inert', '');
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      sheet.current
        ?.querySelector<HTMLElement>('[data-autofocus], button, a[href], input')
        ?.focus();
    });

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') close();
      if (event.key !== 'Tab') return;

      const focusableElements =
        sheet.current?.querySelectorAll<HTMLElement>('button, a[href], input');
      if (!focusableElements?.length) return;

      const first = focusableElements[0];
      const last = focusableElements[focusableElements.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', handleKeyDown);
      appShell?.removeAttribute('inert');
      document.body.style.overflow = previousOverflow;
    };
  }, [close]);

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
