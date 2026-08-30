import { useTranslation } from 'react-i18next';
import { dashboardDemoData } from '../dashboardDemoData';
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

      <div className={styles.visual} aria-hidden="true">
        <div className={styles.donut}>
          <svg viewBox="0 0 42 42">
            <circle className={styles.donutTrack} cx="21" cy="21" pathLength="100" r="15.9155" />
            {dashboardDemoData.allocations.map((entry) => (
              <circle
                className={`${styles.donutSegment} ${styles[entry.tone]}`}
                cx="21"
                cy="21"
                key={entry.labelKey}
                pathLength="100"
                r="15.9155"
                strokeDasharray={entry.dashArray}
                strokeDashoffset={entry.dashOffset}
              />
            ))}
          </svg>
          <span>{dashboardDemoData.allocations.length}</span>
          <small>{t('dashboard.allocation.classLabel')}</small>
        </div>
        <div className={styles.stack}>
          {dashboardDemoData.allocations.map((entry) => (
            <span className={styles[entry.tone]} key={entry.labelKey} />
          ))}
        </div>
      </div>

      <ul className={styles.list}>
        {dashboardDemoData.allocations.map((entry) => (
          <li key={entry.labelKey}>
            <span className={`${styles.dot} ${styles[entry.tone]}`} />
            <span>{t(entry.labelKey)}</span>
            <strong>{entry.percentage}</strong>
            <span className="money">{entry.value}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
