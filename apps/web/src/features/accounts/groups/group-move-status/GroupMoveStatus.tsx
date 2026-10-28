import { useTranslation } from 'react-i18next';
import styles from './GroupMoveStatus.module.css';

interface GroupMoveStatusProps {
  /** Announcement of the last saved move, or null when nothing was moved. */
  announcement: string | null;
  failure: 'stale' | 'failed' | null;
  onReload: () => void;
  /** Absent when the last move cannot be replayed. */
  onRetry: (() => void) | null;
}

/** Polite announcement of a saved move and the recovery actions of a refused one. */
export function GroupMoveStatus({
  announcement,
  failure,
  onReload,
  onRetry,
}: GroupMoveStatusProps) {
  const { t } = useTranslation();

  return (
    <>
      <p aria-live="polite" className="sr-only" role="status">
        {announcement}
      </p>
      {failure ? (
        <p className={styles.alert} role="alert">
          {t(
            failure === 'stale'
              ? 'accountGroups.errors.staleOrder'
              : 'accountGroups.reorder.moveFailed',
          )}{' '}
          {failure === 'failed' && onRetry ? (
            <>
              <button className="secondary-action" onClick={onRetry} type="button">
                {t('accountGroups.reorder.retry')}
              </button>{' '}
            </>
          ) : null}
          <button className="secondary-action" onClick={onReload} type="button">
            {t('accountGroups.reorder.reload')}
          </button>
        </p>
      ) : null}
    </>
  );
}
