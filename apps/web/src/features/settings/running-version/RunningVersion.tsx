import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './RunningVersion.module.css';

interface RunningVersionProps {
  /** The version the API reports; `undefined` while the status loads, `null` when it could not be read. */
  version: string | null | undefined;
}

/**
 * The version of the running installation, read from the status the shell
 * already loads. An unreadable status shows nothing rather than a guess; the
 * server's own `unknown` placeholder is shown as it is.
 */
export function RunningVersion({ version }: RunningVersionProps) {
  const { t } = useTranslation();

  if (version === null) {
    return null;
  }

  return (
    <p className={styles.version}>
      {version === undefined ? (
        <>
          {t('settings.version.label')} <SkeletonBlock className={styles.pending} />
        </>
      ) : (
        t('settings.version.value', { version })
      )}
    </p>
  );
}
