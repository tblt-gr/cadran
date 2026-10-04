import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatCalendarMonth } from '@/lib/decimal';
import styles from './MonthEndPicker.module.css';

/**
 * Chooses one of the due month ends (oldest first) and opens its valuation. Disabled while the
 * due months load; hidden once the server reports nothing due.
 */
export function MonthEndPicker({
  disabled,
  dueMonths,
  onPick,
}: {
  disabled: boolean;
  /** Null while the summary is loading. */
  dueMonths: readonly string[] | null;
  onPick: (month: string) => void;
}) {
  const { i18n } = useTranslation();
  const [chosen, setChosen] = useState<string | null>(null);
  if (dueMonths !== null && dueMonths.length === 0) return null;
  const months = dueMonths ?? [];
  const selected = chosen !== null && months.includes(chosen) ? chosen : (months[0] ?? '');

  return (
    <div className={styles.picker}>
      <label className={styles.field}>
        <span className="sr-only">Mois à valoriser</span>
        <select
          disabled={disabled || dueMonths === null}
          onChange={(event) => setChosen(event.target.value)}
          value={selected}
        >
          {months.map((month) => (
            <option key={month} value={month}>
              {formatCalendarMonth(`${month}-01`, i18n.language)}
            </option>
          ))}
        </select>
      </label>
      <button
        className="secondary-action"
        disabled={disabled || dueMonths === null}
        onClick={() => onPick(selected)}
        type="button"
      >
        Valoriser une fin de mois
      </button>
    </div>
  );
}
