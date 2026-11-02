import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './UpcomingNotice.module.css';

/** "Inclut N transaction(s) à venir" with a link to the setting; nothing when none is counted. */
export function UpcomingNotice({
  includesUpcoming,
  upcomingCount,
}: {
  includesUpcoming: boolean;
  upcomingCount: number;
}) {
  const { t } = useTranslation();
  if (!includesUpcoming || upcomingCount <= 0) return null;

  return (
    <p className={styles.notice}>
      {t('settings.interface.notice.includes', { count: upcomingCount })}{' '}
      <a
        href="/parameters/interface"
        onClick={(event) => handleClientNavigation(event, '/parameters/interface')}
      >
        {t('settings.interface.notice.link')}
      </a>
    </p>
  );
}
