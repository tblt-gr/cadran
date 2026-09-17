import { useTranslation } from 'react-i18next';
import styles from './ActiveFilterChips.module.css';

export interface Chip {
  key: string;
  label: string;
  onClear: () => void;
}

interface ActiveFilterChipsProps {
  chips: readonly Chip[];
  onReset: () => void;
}

/**
 * The row of chips summarising every applied filter, each clearable on its own, with one
 * button resetting all of them. Renders nothing when no filter is active.
 */
export function ActiveFilterChips({ chips, onReset }: ActiveFilterChipsProps) {
  const { t } = useTranslation();

  if (chips.length === 0) {
    return null;
  }

  return (
    <div aria-label={t('transactions.filters.activeLabel')} className={styles.chips} role="group">
      {chips.map((chip) => (
        <button className={styles.chip} key={chip.key} onClick={chip.onClear} type="button">
          {chip.label}
          <span aria-hidden="true"> ×</span>
          <span className="sr-only">
            {t('transactions.filters.clearChip', { label: chip.label })}
          </span>
        </button>
      ))}
      <button className="secondary-action" onClick={onReset} type="button">
        {t('transactions.filters.reset')}
      </button>
    </div>
  );
}
