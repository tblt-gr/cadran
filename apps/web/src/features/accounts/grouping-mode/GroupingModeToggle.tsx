import { useTranslation } from 'react-i18next';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import type { GroupingMode } from './groupingMode';

interface GroupingModeToggleProps {
  className?: string;
  disabled?: boolean;
  icon?: IconName;
  /** Shows the label next to the icon, as every header action does. */
  labelled?: boolean;
  mode: GroupingMode;
  onChange: (mode: GroupingMode) => void;
}

/** Circular icon toggle: pressed means sub-group detail (`PRIMARY`). */
export function GroupingModeToggle({
  className = 'icon-ghost',
  disabled = false,
  icon = 'layers',
  labelled = false,
  mode,
  onChange,
}: GroupingModeToggleProps) {
  const { t } = useTranslation();
  const label = t('accounts.grouping.toggle');

  return (
    <button
      aria-label={labelled ? undefined : label}
      aria-pressed={mode === 'PRIMARY'}
      className={className}
      disabled={disabled}
      onClick={() => onChange(mode === 'PRIMARY' ? 'PARENT' : 'PRIMARY')}
      title={labelled ? undefined : label}
      type="button"
    >
      <Icon name={icon} size={labelled ? 18 : 16} />
      {labelled ? label : null}
    </button>
  );
}
