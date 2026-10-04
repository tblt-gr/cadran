import type { ReactNode } from 'react';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './PortfolioKpiGrid.module.css';

const sizes = {
  display: styles.sizeDisplay,
  lead: styles.sizeLead,
  compact: styles.sizeCompact,
};

/** One indicator: its label and explanation, the server value, and what qualifies it. */
export function KpiFigure({
  label,
  explainLabel,
  value,
  tone = '',
  note,
  reason,
  size,
  onExplain,
}: {
  label: string;
  explainLabel: string;
  /** `null` while loading: only the value becomes a block. */
  value: ReactNode | null;
  tone?: string;
  note?: string;
  reason?: string;
  size: 'display' | 'lead' | 'compact';
  onExplain: (() => void) | null;
}) {
  return (
    <article className={`${styles.figure} ${sizes[size]}`}>
      <div className={styles.heading}>
        <h3>{label}</h3>
        <InfoButton disabled={!onExplain} label={explainLabel} onClick={onExplain ?? undefined} />
      </div>
      <div className={`${styles.value} ${tone}`}>
        {value ?? <SkeletonBlock className={styles.valueSkeleton} />}
      </div>
      {note ? <p className={styles.note}>{note}</p> : null}
      {reason ? <StatusBadge tone="warning">{reason}</StatusBadge> : null}
    </article>
  );
}
