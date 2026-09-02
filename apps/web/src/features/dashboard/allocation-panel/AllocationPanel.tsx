import { useTranslation } from 'react-i18next';
import { dashboardDemoData } from '@/features/dashboard/dashboardDemoData';
import styles from './AllocationPanel.module.css';

export function AllocationPanel() {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.card}`} aria-labelledby="allocation-title">
      <div className={styles.heading}>
        <div>
          <h2 id="allocation-title">{t('dashboard.allocation.title')}</h2>
          <p>{t('dashboard.allocation.description')}</p>
        </div>
        <a className={styles.link} href="/accounts">
          {t('dashboard.allocation.viewAccounts')}
        </a>
      </div>

      <ul className={styles.list}>
        {dashboardDemoData.allocations.map((entry) => (
          <li key={entry.labelKey}>
            <span className={styles.name}>{t(entry.labelKey)}</span>
            <span className={styles.share}>{entry.percentage}</span>
            <strong className={`money ${styles.amount}`}>{entry.value}</strong>
            <span className={styles.track} aria-hidden="true">
              <span
                className={`${styles.fill} ${styles[entry.tone]}`}
                style={{ width: `${entry.share}%` }}
              />
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
