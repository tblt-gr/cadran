import type { NetWorth } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { useGroupingMode } from '@/features/accounts/grouping-mode/useGroupingMode';
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
 * The exclusive weight of each group, by parent group (roll-up of root groups) or by sub-group
 * (direct members only). Either way every account sits in exactly one slice, so no euro is read
 * twice. Every percentage is the
 * two-decimal display string the backend produced; the bar repeats it and
 * carries no meaning of its own.
 */
export function AllocationPanel({ netWorth }: AllocationPanelProps) {
  const { t } = useTranslation();
  const [view, setView] = useState<ChartView>('treemap');
  const [mode, setMode] = useGroupingMode('cadran.synthese.grouping', 'PRIMARY');
  const hasGroups = netWorth.allocation.some((entry) => entry.depth === 1);

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
        <div className={styles.actions}>
          <GroupingModeToggle mode={mode} onChange={setMode} />
          <a className={styles.link} href="/accounts/groups">
            {t('dashboard.allocation.viewGroups')}
          </a>
        </div>
      </div>

      {!hasGroups ? (
        <p className={styles.empty}>{t('dashboard.allocation.empty')}</p>
      ) : (
        <div className={view === 'pie' ? styles.pieLayout : styles.treeLayout}>
          <AllocationCharts
            allocation={netWorth.allocation}
            mode={mode}
            onViewChange={setView}
            reason={netWorth.reason}
            total={netWorth.total}
          />
          {view === 'pie' ? (
            <AllocationLegend
              allocation={netWorth.allocation}
              mode={mode}
              reason={netWorth.reason}
            />
          ) : null}
        </div>
      )}
    </section>
  );
}
