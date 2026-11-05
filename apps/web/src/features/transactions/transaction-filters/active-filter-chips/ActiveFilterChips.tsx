import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Chip } from '@/components/ui/chip/Chip';
import styles from './ActiveFilterChips.module.css';

export interface FilterChip {
  key: string;
  label: ReactNode;
  /** Accessible name of the remove button, since `label` may be a skeleton block. */
  removeLabel: string;
  onClear: () => void;
}

interface ActiveFilterChipsProps {
  chips: readonly FilterChip[];
  onReset: () => void;
}

/**
 * The row of chips summarising every applied filter, each clearable on its own, with one
 * text button clearing all of them. Renders nothing when no filter is active.
 */
export function ActiveFilterChips({ chips, onReset }: ActiveFilterChipsProps) {
  const { t } = useTranslation();

  if (chips.length === 0) {
    return null;
  }

  return (
    <div aria-label={t('transactions.filters.activeLabel')} className={styles.chips} role="group">
      {chips.map((chip) => (
        <Chip
          key={chip.key}
          label={chip.label}
          onRemove={chip.onClear}
          removeLabel={chip.removeLabel}
        />
      ))}
      <button className={styles.reset} onClick={onReset} type="button">
        {t('transactions.filters.reset')}
      </button>
    </div>
  );
}
