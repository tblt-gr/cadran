import { useId, type ReactNode, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useDialogOverlay } from '@/hooks/use-dialog-overlay';
import styles from './Drawer.module.css';

interface DrawerProps {
  children: ReactNode;
  close: () => void;
  /** Sticky actions under the scrolling body. */
  footer?: ReactNode;
  /** The control that opened the drawer; it takes the focus back on close. */
  returnFocus?: RefObject<HTMLElement | null>;
  title: string;
}

/**
 * Side panel anchored to the right edge, over the shared backdrop. It shares the focus trap,
 * Escape and focus return of `Modal`; a nested `Modal` stacks above it.
 */
export function Drawer({ children, close, footer, returnFocus, title }: DrawerProps) {
  const { t } = useTranslation();
  const container = useDialogOverlay(close, returnFocus);
  const titleId = useId();

  return createPortal(
    <div className={styles.backdrop} onMouseDown={close}>
      <section
        aria-labelledby={titleId}
        aria-modal="true"
        className={styles.drawer}
        onMouseDown={(event) => event.stopPropagation()}
        ref={container}
        role="dialog"
      >
        <header className={styles.heading}>
          <h2 id={titleId}>{title}</h2>
          <button
            aria-label={t('actions.close')}
            className="icon-ghost"
            onClick={close}
            type="button"
          >
            <Icon name="close" />
          </button>
        </header>
        <div className={styles.body}>{children}</div>
        {footer ? <footer className={styles.footer}>{footer}</footer> : null}
      </section>
    </div>,
    document.body,
  );
}
