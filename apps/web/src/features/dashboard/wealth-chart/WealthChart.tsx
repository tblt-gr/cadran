import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { dashboardDemoData } from '@/features/dashboard/dashboardDemoData';
import { formatDemoMonth } from '@/features/dashboard/formatDemoDate';
import styles from './WealthChart.module.css';

export function WealthChart() {
  const { i18n, t } = useTranslation();

  return (
    <figure className={styles.chart} aria-labelledby="wealth-chart-title">
      <figcaption id="wealth-chart-title">{t('dashboard.chart.title')}</figcaption>
      <svg
        aria-label={t('dashboard.chart.summary', {
          endPeriod: formatDemoMonth(
            dashboardDemoData.wealthHistory[dashboardDemoData.wealthHistory.length - 1].period,
            i18n.language,
          ),
          startPeriod: formatDemoMonth(dashboardDemoData.wealthHistory[0].period, i18n.language),
        })}
        className={styles.plot}
        preserveAspectRatio="none"
        role="img"
        viewBox="0 0 620 170"
      >
        <defs>
          <linearGradient id="wealth-chart-area" x1="0" x2="0" y1="0" y2="1">
            <stop className={styles.areaTop} offset="0" />
            <stop className={styles.areaBottom} offset="1" />
          </linearGradient>
        </defs>
        <g className={styles.grid}>
          <path d="M0 30H620M0 90H620" />
        </g>
        <path
          className={styles.area}
          d="M0 150 C58 147 84 132 120 134 S198 120 236 112 S310 99 354 96 S430 86 472 74 S578 50 620 40 V170 H0Z"
        />
        <path
          className={styles.line}
          d="M0 150 C58 147 84 132 120 134 S198 120 236 112 S310 99 354 96 S430 86 472 74 S578 50 620 40"
        />
      </svg>
      <details className={styles.details}>
        <summary>
          <span className={styles.disclosure}>
            <Icon name="chevron-right" size={14} />
          </span>
          {t('dashboard.chart.showTable')}
        </summary>
        <div className={styles.tableWrap}>
          <table aria-label={t('dashboard.chart.title')} className={styles.table}>
            <thead>
              <tr>
                <th scope="col">{t('dashboard.chart.monthColumn')}</th>
                <th scope="col">{t('dashboard.chart.valueColumn')}</th>
              </tr>
            </thead>
            <tbody>
              {dashboardDemoData.wealthHistory.map((entry) => (
                <tr key={entry.period}>
                  <th scope="row">{formatDemoMonth(entry.period, i18n.language)}</th>
                  <td>{entry.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </figure>
  );
}
