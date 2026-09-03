import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './Toast.module.css';

const TOAST_DURATION_MS = 4_000;

interface ToastProps {
  children: string;
  onDismiss: () => void;
}

export function Toast({ children, onDismiss }: ToastProps) {
  const { t } = useTranslation();
  const onDismissRef = useRef(onDismiss);

  useEffect(() => {
    onDismissRef.current = onDismiss;
  });

  // A new message restarts the timer; a new callback from the parent must not.
  useEffect(() => {
    const timer = window.setTimeout(() => onDismissRef.current(), TOAST_DURATION_MS);

    return () => window.clearTimeout(timer);
  }, [children]);

  return createPortal(
    <div className={styles.region}>
      <div className={styles.toast} role="status">
        <p>{children}</p>
        <button
          aria-label={t('actions.close')}
          className={styles.close}
          onClick={onDismiss}
          type="button"
        >
          <Icon name="close" size={16} />
        </button>
      </div>
    </div>,
    document.body,
  );
}
