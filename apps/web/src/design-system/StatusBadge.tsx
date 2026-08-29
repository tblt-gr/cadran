import { Icon, type IconName } from './Icon';

type StatusTone = 'info' | 'negative' | 'positive' | 'warning';

interface StatusBadgeProps {
  children: string;
  icon?: IconName;
  tone: StatusTone;
}

export function StatusBadge({ children, icon = 'alert', tone }: StatusBadgeProps) {
  return (
    <span className={`status-badge status-badge--${tone}`}>
      <Icon name={icon} size={15} />
      <span>{children}</span>
    </span>
  );
}
