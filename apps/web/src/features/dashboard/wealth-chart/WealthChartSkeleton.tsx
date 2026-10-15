import { useTranslation } from 'react-i18next';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './WealthChart.module.css';

/** WealthChart while the history loads: the same figure, caption and table toggle, inert. */
export function WealthChartSkeleton() {
  const { t } = useTranslation();

  return (
    <figure className={styles.chart} aria-labelledby="wealth-chart-skeleton-title">
      <figcaption id="wealth-chart-skeleton-title">{t('dashboard.chart.title')}</figcaption>
      <CurveChartSkeleton />
      <details className={styles.details}>
        <summary aria-disabled="true" onClick={(event) => event.preventDefault()}>
          <span className={styles.disclosure}>
            <Icon name="chevron-right" size={14} />
          </span>
          {t('dashboard.chart.showTable')}
        </summary>
      </details>
    </figure>
  );
}
