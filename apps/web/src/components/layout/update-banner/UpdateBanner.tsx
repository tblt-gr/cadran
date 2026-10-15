import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './UpdateBanner.module.css';
import { useWaitingWorker } from './useWaitingWorker';

interface UpdateBannerProps {
  /** Absent where the browser has no service worker support. */
  container?: ServiceWorkerContainer;
  reload?: () => void;
}

const defaultContainer =
  typeof navigator !== 'undefined' && 'serviceWorker' in navigator
    ? navigator.serviceWorker
    : undefined;

/**
 * Non-blocking notice shown when a new version of the application waits in the
 * service worker. Reloading is the user's choice: it hands over to the new worker
 * first so the page never runs against a cache from another build.
 */
export function UpdateBanner({
  container = defaultContainer,
  reload = () => window.location.reload(),
}: UpdateBannerProps) {
  const { t } = useTranslation();
  const waiting = useWaitingWorker(container);
  const isRequested = useRef(false);

  useEffect(() => {
    if (!container) return;
    const onControllerChange = () => {
      if (isRequested.current) reload();
    };
    container.addEventListener('controllerchange', onControllerChange);
    return () => container.removeEventListener('controllerchange', onControllerChange);
  }, [container, reload]);

  if (!waiting) return null;

  return (
    <div className={styles.banner} role="status">
      <p className={styles.message}>{t('app.update.available')}</p>
      <button
        className={`primary-action ${styles.action}`}
        type="button"
        onClick={() => {
          isRequested.current = true;
          waiting.postMessage('SKIP_WAITING');
        }}
      >
        {t('app.update.reload')}
      </button>
    </div>
  );
}
