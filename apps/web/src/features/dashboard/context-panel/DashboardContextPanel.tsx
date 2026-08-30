import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { dashboardDemoData } from '@/features/dashboard/dashboardDemoData';
import { formatDemoFullDate } from '@/features/dashboard/formatDemoDate';
import styles from './DashboardContextPanel.module.css';

export function DashboardContextPanel() {
  const { i18n, t } = useTranslation();

  return (
    <aside className={styles.panel} aria-labelledby="quality-title">
      <div className={styles.heading}>
        <div>
          <h2 id="quality-title">{t('dashboard.quality.title')}</h2>
          <p>{t('dashboard.quality.description')}</p>
        </div>
        <StatusBadge tone="warning">{t('states.stale.label')}</StatusBadge>
      </div>

      <section className={styles.item}>
        <div className={`${styles.itemIcon} ${styles.warning}`}>
          <Icon name="alert" />
        </div>
        <div>
          <h3>{t('states.stale.title')}</h3>
          <p>
            {t('states.stale.description', {
              date: formatDemoFullDate(dashboardDemoData.lastBalanceDate, i18n.language),
            })}
          </p>
        </div>
      </section>

      <section className={styles.item}>
        <div className={`${styles.itemIcon} ${styles.info}`}>
          <Icon name="transactions" />
        </div>
        <div>
          <h3>{t('states.empty.title')}</h3>
          <p>{t('states.empty.description')}</p>
        </div>
      </section>

      <section className={styles.item}>
        <div className={`${styles.itemIcon} ${styles.negative}`}>
          <Icon name="alert" />
        </div>
        <div>
          <h3>{t('states.notCalculable.title')}</h3>
          <p>{t('states.notCalculable.explanation')}</p>
        </div>
      </section>
    </aside>
  );
}
