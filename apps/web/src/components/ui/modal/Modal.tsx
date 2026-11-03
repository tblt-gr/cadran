import { useId, type ReactNode, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useDialogOverlay } from '@/hooks/use-dialog-overlay';
import { type ModalIntent } from './modalIntent';
import styles from './Modal.module.css';

// Editing dialog checklist: pass an `intent` (its fixed action label), lay the fields out with
// `FormGrid`, fold secondary fields in a `FormSection`, and end the form with `ModalActions`.
interface ModalProps {
  children: ReactNode;
  close: () => void;
  /** @deprecated Use `intent`; removed once no caller is left. */
  eyebrow?: string;
  /** Optional only during the migration window; `intent` wins over `eyebrow`. */
  intent?: ModalIntent;
  returnFocus?: RefObject<HTMLElement | null>;
  title: string;
}

export function Modal({ children, close, eyebrow, intent, returnFocus, title }: ModalProps) {
  const { t } = useTranslation();
  const container = useDialogOverlay(close, returnFocus);
  const titleId = useId();
  const label = intent ? t(`modal.intents.${intent}`) : eyebrow;

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
            {label ? <p>{label}</p> : null}
            <h2 id={titleId}>{title}</h2>
          </div>
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
      </section>
    </div>,
    document.body,
  );
}
