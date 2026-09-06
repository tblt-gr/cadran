import type { NetWorthAllocationEntry, NetWorthReason } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { AllocationBar } from './AllocationBar';
import styles from './AllocationPanel.module.css';

interface AllocationLegendProps {
  entries: NetWorthAllocationEntry[];
  reason: NetWorthReason | null;
}

/**
 * Top-level exclusive groups as a list: label, backend share, amount, bar.
 */
export function AllocationLegend({ entries, reason }: AllocationLegendProps) {
  const { t } = useTranslation();

  return (
    <ul aria-label={t('dashboard.allocation.title')} className={styles.list}>
      {entries.map((entry) => (
        <li key={entry.groupId}>
          <span className={styles.name}>{entry.label}</span>
          <span className={styles.share}>
            {entry.share.percentDisplay === null
              ? t('states.notCalculable.label')
              : formatSharePercent(entry.share.percentDisplay, { fractionDigits: 2 })}
          </span>
          <strong className={`money ${styles.amount}`}>
            <NetWorthFigure amount={entry.value} reason={reason} />
          </strong>
          <AllocationBar percent={entry.share.percentDisplay} />
        </li>
      ))}
    </ul>
  );
}
