import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { formatMonthLabel } from '@/lib/monthLabel';
import styles from './MonthStepper.module.css';

interface MonthStepperProps {
  /** `YYYY-MM`. */
  month: string;
  /** Receives the direction of the step; the caller decides how it enters the history. */
  onStep: (offset: -1 | 1) => void;
  /** Disables both arrows while the screen is still being built. */
  disabled?: boolean;
}

/** Walks the list one calendar month at a time around the month it shows. */
export function MonthStepper({ disabled = false, month, onStep }: MonthStepperProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.stepper}>
      <button
        aria-label={t('transactions.filters.previousMonth')}
        className="icon-button"
        disabled={disabled}
        onClick={() => onStep(-1)}
        type="button"
      >
        <Icon name="chevron-left" size={18} />
      </button>
      <p aria-live="polite" className={styles.month}>
        {formatMonthLabel(month, i18n.language)}
      </p>
      <button
        aria-label={t('transactions.filters.nextMonth')}
        className="icon-button"
        disabled={disabled}
        onClick={() => onStep(1)}
        type="button"
      >
        <Icon name="chevron-right" size={18} />
      </button>
    </div>
  );
}
