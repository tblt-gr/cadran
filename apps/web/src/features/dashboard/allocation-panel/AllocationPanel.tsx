import type { NetWorth } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AllocationCharts,
  type ChartView,
} from '@/features/dashboard/allocation-charts/AllocationCharts';
import { AllocationLegend } from './AllocationLegend';
import styles from './AllocationPanel.module.css';

interface AllocationPanelProps {
  netWorth: NetWorth;
}

/**
 * The exclusive weight of each top-level group.
 *
 * Only the first level is listed: a child already counts inside its parent, so
 * showing both would let the same euro be read twice. Every percentage is the
 * two-decimal display string the backend produced; the bar repeats it and
 * carries no meaning of its own.
 */
export function AllocationPanel({ netWorth }: AllocationPanelProps) {
  const { t } = useTranslation();
  const [view, setView] = useState<ChartView>('treemap');
  const roots = netWorth.allocation.filter((entry) => entry.depth === 1);

  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby="allocation-title"
      data-allocation-view={view}
    >
      <div className={styles.heading}>
        <div>
          <h2 id="allocation-title">{t('dashboard.allocation.title')}</h2>
          <p>{t('dashboard.allocation.description')}</p>
        </div>
        <a className={styles.link} href="/account-groups">
          {t('dashboard.allocation.viewGroups')}
        </a>
      </div>

      {roots.length === 0 ? (
        <p className={styles.empty}>{t('dashboard.allocation.empty')}</p>
      ) : (
        <div className={view === 'pie' ? styles.pieLayout : styles.treeLayout}>
          <AllocationCharts
            allocation={netWorth.allocation}
            onViewChange={setView}
            reason={netWorth.reason}
            total={netWorth.total}
          />
          {view === 'pie' ? <AllocationLegend entries={roots} reason={netWorth.reason} /> : null}
        </div>
      )}
    </section>
  );
}
