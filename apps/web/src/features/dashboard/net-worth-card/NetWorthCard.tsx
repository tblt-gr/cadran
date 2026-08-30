import { useTranslation } from 'react-i18next';
import { Icon } from '../../../components/ui/icon/Icon';
import { MoneyValue } from '../../../components/ui/money-value/MoneyValue';
import { dashboardDemoData } from '../dashboardDemoData';
import { formatDemoMonth } from '../formatDemoDate';
import { WealthChart } from '../wealth-chart/WealthChart';
import styles from './NetWorthCard.module.css';

export function NetWorthCard() {
  const { i18n, t } = useTranslation();

  return (
    <section className={`card ${styles.card}`} aria-labelledby="net-worth-title">
      <div className={styles.header}>
        <div>
          <h2 id="net-worth-title">{t('dashboard.netWorth.title')}</h2>
          <p className={`money ${styles.amount}`}>
            <MoneyValue value={dashboardDemoData.netWorth} />
          </p>
          <p className={styles.delta}>
            <span
              aria-label={t('dashboard.netWorth.increaseAccessible', {
                amount: dashboardDemoData.delta,
              })}
              className={styles.positiveDelta}
            >
              <Icon name="arrow-up" size={16} />
              <span aria-hidden="true">+ {dashboardDemoData.delta}</span>
            </span>
            <span>
              {t('dashboard.netWorth.deltaPeriod', {
                period: formatDemoMonth(dashboardDemoData.deltaSincePeriod, i18n.language),
              })}
            </span>
          </p>
        </div>
        <div className={styles.periodControl} aria-label={t('dashboard.period.label')}>
          <button
            aria-label={t('dashboard.period.previous')}
            className="icon-button"
            disabled
            type="button"
          >
            <Icon name="chevron-left" />
          </button>
          <span>{formatDemoMonth(dashboardDemoData.currentPeriod, i18n.language)}</span>
          <button
            aria-label={t('dashboard.period.next')}
            className="icon-button"
            disabled
            type="button"
          >
            <Icon name="chevron-right" />
          </button>
        </div>
      </div>
      <WealthChart />
    </section>
  );
}
