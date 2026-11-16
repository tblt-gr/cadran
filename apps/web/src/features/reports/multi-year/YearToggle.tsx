import { Icon } from '@/components/ui/icon/Icon';
import localStyles from './ContinuousDetailedReport.module.css';

interface YearToggleProps {
  label: number;
  open: boolean;
  onToggle: (year: number) => void;
}

/** Folds or unfolds the months of one year. */
export function YearToggle({ label, open, onToggle }: YearToggleProps) {
  return (
    <button
      aria-expanded={open}
      className={localStyles.yearToggle}
      onClick={() => onToggle(label)}
      type="button"
    >
      <span aria-hidden="true" className={localStyles.chevron}>
        <Icon name="chevron-right" size={14} />
      </span>
      {label}
    </button>
  );
}
