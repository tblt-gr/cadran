import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { latestValuationDay } from '@/features/dashboard/net-worth/latestValuationDay';
import { useNetWorth } from '@/features/dashboard/net-worth/useNetWorth';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './DashboardContextPanel.module.css';

type FreshnessTone = 'info' | 'negative' | 'positive' | 'warning';

export function DashboardContextPanel() {
  const { i18n, t } = useTranslation();
  const netWorth = useNetWorth();

  let tone: FreshnessTone = 'warning';
  let badge: string;
  let description: string;

  if (netWorth.isPending) {
    tone = 'info';
    badge = t('dashboard.netWorth.loading');
    description = t('states.loading.title');
  } else if (netWorth.isError || netWorth.data === undefined) {
    // An outage is not a missing valuation. Saying so would hide a server
    // failure behind a data-quality warning.
    tone = 'negative';
    badge = t('states.error.title');
    description = t('states.error.description');
  } else {
    const lastValued = latestValuationDay(netWorth.data);
    tone = netWorth.data.quality === 'CURRENT' ? 'positive' : 'warning';
    badge = t(`dashboard.netWorth.qualities.${netWorth.data.quality}`);
    description =
      lastValued === null
        ? t('states.stale.unknown')
        : t('states.stale.description', { date: formatCalendarDay(lastValued, i18n.language) });
  }

  return (
    <aside className={styles.panel} aria-labelledby="quality-title">
      <div className={styles.heading}>
        <div>
          <h2 id="quality-title">{t('dashboard.quality.title')}</h2>
          <p>{t('dashboard.quality.description')}</p>
        </div>
        <StatusBadge tone={tone}>{badge}</StatusBadge>
      </div>

      <section className={styles.item}>
        <div className={`${styles.itemIcon} ${styles.warning}`}>
          <Icon name="alert" />
        </div>
        <div>
          <h3>{t('states.stale.title')}</h3>
          <p>{description}</p>
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
