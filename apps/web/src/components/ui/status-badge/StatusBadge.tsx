import { Icon, type IconName } from '@/components/ui/icon/Icon';
import styles from './StatusBadge.module.css';

type StatusTone = 'info' | 'negative' | 'positive' | 'warning';

interface StatusBadgeProps {
  children: string;
  icon?: IconName;
  tone: StatusTone;
}

export function StatusBadge({ children, icon = 'alert', tone }: StatusBadgeProps) {
  return (
    <span className={`${styles.badge} ${styles[tone]}`}>
      <Icon name={icon} size={15} />
      <span>{children}</span>
    </span>
  );
}
