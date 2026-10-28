import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import type { GroupingMode } from './groupingMode';

interface GroupingModeToggleProps {
  disabled?: boolean;
  mode: GroupingMode;
  onChange: (mode: GroupingMode) => void;
}

/** Circular icon toggle: pressed means sub-group detail (`PRIMARY`). */
export function GroupingModeToggle({ disabled = false, mode, onChange }: GroupingModeToggleProps) {
  const { t } = useTranslation();
  const label = t('accounts.grouping.toggle');

  return (
    <button
      aria-label={label}
      aria-pressed={mode === 'PRIMARY'}
      className="icon-ghost"
      disabled={disabled}
      onClick={() => onChange(mode === 'PRIMARY' ? 'PARENT' : 'PRIMARY')}
      title={label}
      type="button"
    >
      <Icon name="layers" size={16} />
    </button>
  );
}
