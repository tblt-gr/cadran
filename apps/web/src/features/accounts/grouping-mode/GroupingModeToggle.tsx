import { useTranslation } from 'react-i18next';
import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import type { GroupingMode } from './groupingMode';

interface GroupingModeToggleProps {
  className?: string;
  disabled?: boolean;
  icon?: IconName;
  /** Visible text next to the icon, for toolbars of labelled actions. */
  visibleLabel?: ReactNode;
  mode: GroupingMode;
  onChange: (mode: GroupingMode) => void;
}

/** Circular icon toggle: pressed means sub-group detail (`PRIMARY`). */
export function GroupingModeToggle({
  className = 'icon-ghost',
  disabled = false,
  icon = 'layers',
  visibleLabel,
  mode,
  onChange,
}: GroupingModeToggleProps) {
  const { t } = useTranslation();
  const label = t('accounts.grouping.toggle');

  return (
    <button
      aria-label={label}
      aria-pressed={mode === 'PRIMARY'}
      className={className}
      disabled={disabled}
      onClick={() => onChange(mode === 'PRIMARY' ? 'PARENT' : 'PRIMARY')}
      title={label}
      type="button"
    >
      <Icon name={icon} size={visibleLabel ? undefined : 16} />
      {visibleLabel}
    </button>
  );
}
