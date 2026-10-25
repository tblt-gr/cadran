import { useTranslation } from 'react-i18next';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import type { MultiYearDisplay } from './yearViewSearch';
import styles from './DisplayModeSwitch.module.css';

const MODES: readonly {
  icon: IconName;
  labelKey: 'compact' | 'detailed';
  value: MultiYearDisplay;
}[] = [
  { icon: 'layers', labelKey: 'compact', value: 'compact' },
  { icon: 'reorder', labelKey: 'detailed', value: 'detailed' },
];

/** Compact or detailed multi-year table, as two circular icon buttons named by `aria-label`. */
export function DisplayModeSwitch({
  onChange,
  value,
}: {
  onChange: (display: MultiYearDisplay) => void;
  value: MultiYearDisplay;
}) {
  const { t } = useTranslation();

  return (
    <div aria-label={t('reports.multiYear.displayMode')} className={styles.switch} role="group">
      {MODES.map((mode) => {
        const label = t(`reports.multiYear.${mode.labelKey}`);
        return (
          <button
            aria-label={label}
            aria-pressed={value === mode.value}
            className={`icon-ghost ${styles.mode}`}
            key={mode.value}
            onClick={() => onChange(mode.value)}
            title={label}
            type="button"
          >
            <Icon name={mode.icon} size={16} />
          </button>
        );
      })}
    </div>
  );
}
