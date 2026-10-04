import { HISTORY_PERIODS, type HistoryPeriod } from './valueHistoryRange';
import styles from './ValueHistoryCard.module.css';

export function PeriodControl({
  disabled = false,
  onChange,
  value,
}: {
  disabled?: boolean;
  onChange: (period: HistoryPeriod) => void;
  value: HistoryPeriod;
}) {
  return (
    <div className={styles.controls} role="group" aria-label="Période">
      {HISTORY_PERIODS.map((period) => (
        <button
          aria-pressed={value === period.value}
          className="secondary-action"
          disabled={disabled}
          key={period.value}
          onClick={() => onChange(period.value)}
          type="button"
        >
          {period.label}
        </button>
      ))}
    </div>
  );
}
