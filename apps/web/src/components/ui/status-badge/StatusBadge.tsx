import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import styles from './StatusBadge.module.css';

type StatusTone = 'info' | 'negative' | 'neutral' | 'positive' | 'warning';

interface StatusBadgeProps {
  children: ReactNode;
  icon?: IconName;
  tone: StatusTone;
  /** The text follows the calendar or the ledger: visual comparisons skip it. */
  visualMask?: boolean;
}

export function StatusBadge({ children, icon, tone, visualMask }: StatusBadgeProps) {
  return (
    <span className={`${styles.badge} ${styles[tone]}`} data-visual-mask={visualMask || undefined}>
      {icon ? <Icon name={icon} size={15} /> : null}
      <span>{children}</span>
    </span>
  );
}
