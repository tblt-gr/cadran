import { useId, type ReactNode, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useDialogOverlay } from '@/hooks/use-dialog-overlay';
import styles from './Modal.module.css';

interface ModalProps {
  children: ReactNode;
  close: () => void;
  eyebrow?: string;
  returnFocus?: RefObject<HTMLElement | null>;
  title: string;
}

export function Modal({ children, close, eyebrow, returnFocus, title }: ModalProps) {
  const { t } = useTranslation();
  const container = useDialogOverlay(close, returnFocus);
  const titleId = useId();

  return createPortal(
    <div className={styles.backdrop} onMouseDown={close}>
      <section
        aria-labelledby={titleId}
        aria-modal="true"
        className={styles.modal}
        onMouseDown={(event) => event.stopPropagation()}
        ref={container}
        role="dialog"
      >
        <header className={styles.heading}>
          <div>
            {eyebrow ? <p>{eyebrow}</p> : null}
            <h2 id={titleId}>{title}</h2>
          </div>
          <button
            aria-label={t('actions.close')}
            className="icon-button"
            onClick={close}
            type="button"
          >
            <Icon name="close" />
          </button>
        </header>
        <div className={styles.body}>{children}</div>
      </section>
    </div>,
    document.body,
  );
}
