import { groupColor } from '@/features/accounts/group-color';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import type { NetWorthAllocationEntry, NetWorthReason } from '@cadran/api-client';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { useTranslation } from 'react-i18next';
import { AllocationLegend as Legend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { allocationEntries } from './allocationEntries';
import styles from './AllocationPanel.module.css';

interface AllocationLegendProps {
  allocation: NetWorthAllocationEntry[];
  mode?: GroupingMode;
  reason: NetWorthReason | null;
}

/** Net-worth groups of the chosen mode as the shared legend: label, backend share, amount, bar. */
export function AllocationLegend({ allocation, mode = 'PARENT', reason }: AllocationLegendProps) {
  const { t } = useTranslation();

  return (
    <Legend
      items={allocationEntries(allocation, mode).map((entry) => ({
        amount: <NetWorthFigure amount={entry.value} reason={reason} />,
        barPercent: entry.share.percentDisplay,
        key: entry.groupId,
        label: (
          <>
            <GroupMarker color={entry.color} />
            <span title={entry.path}>
              {entry.label}
              {entry.parentLabel === null ? null : (
                <>
                  {' '}
                  <span className={styles.parentLabel} aria-hidden="true">
                    ({entry.parentLabel})
                  </span>
                  <span className="sr-only">{`, ${entry.path}`}</span>
                </>
              )}
            </span>
          </>
        ),
        color: groupColor(entry.color),
        share:
          entry.share.percentDisplay === null ? (
            <EmptyValue label={t('states.notCalculable.label')} />
          ) : (
            formatSharePercent(entry.share.percentDisplay, { fractionDigits: 2 })
          ),
      }))}
      label={t('dashboard.allocation.title')}
    />
  );
}
