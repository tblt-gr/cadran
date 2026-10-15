import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './AllocationCharts.module.css';

/** AllocationCharts while the allocation loads: same toggles, disabled, over a pending plot. */
export function AllocationChartsSkeleton() {
  const { t } = useTranslation();

  return (
    <div className={styles.wrap} data-allocation-view="treemap">
      <div className={styles.toggles} role="group" aria-label={t('dashboard.allocation.chartView')}>
        <button
          aria-label={t('dashboard.allocation.treemap')}
          aria-pressed="true"
          className="icon-ghost"
          disabled
          type="button"
        >
          <Icon name="treemap" size={16} />
        </button>
        <button
          aria-label={t('dashboard.allocation.pie')}
          aria-pressed="false"
          className="icon-ghost"
          disabled
          type="button"
        >
          <Icon name="pie" size={16} />
        </button>
      </div>
      <SkeletonBlock className={styles.plot} />
    </div>
  );
}
