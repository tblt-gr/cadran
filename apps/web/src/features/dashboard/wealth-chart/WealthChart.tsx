import { useTranslation } from 'react-i18next';
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
        role="img"
        viewBox="0 0 620 170"
      >
        <g className={styles.grid}>
          <path d="M20 25H600M20 85H600M20 145H600" />
        </g>
        <path
          className={styles.area}
          d="M20 142 C78 139 104 124 140 126 S218 112 256 104 S330 91 374 88 S450 78 492 66 S558 46 600 31 V145 H20Z"
        />
        <path
          className={styles.line}
          d="M20 142 C78 139 104 124 140 126 S218 112 256 104 S330 91 374 88 S450 78 492 66 S558 46 600 31"
          pathLength="1"
        />
        <circle className={styles.point} cx="600" cy="31" r="4" />
      </svg>
      <details className={styles.details}>
        <summary>{t('dashboard.chart.showTable')}</summary>
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
