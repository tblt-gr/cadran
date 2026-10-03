import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { lastTwelveMonths, startOfYear } from './cashPeriod';
import styles from './CashTab.module.css';

interface Props {
  disabled?: boolean;
  from: string;
  onChange: (range: { from: string; to: string }) => void;
  to: string;
  today: string;
}

/** Period selector shared by the live cash tab and its loading preview. */
export function CashToolbar({ disabled = false, from, onChange, to, today }: Props) {
  return (
    <fieldset className={styles.toolbar} disabled={disabled}>
      <legend className="sr-only">Période des espèces</legend>
      <BusinessDateField
        hint={null}
        label="Du"
        onChange={(value) => onChange({ from: value, to })}
        resetLabel="Début d’année"
        today={startOfYear(today)}
        value={from}
      />
      <BusinessDateField
        hint={null}
        label="Au"
        onChange={(value) => onChange({ from, to: value > today ? today : value })}
        today={today}
        value={to}
      />
      <div className={styles.shortcuts}>
        <button
          className="secondary-action"
          onClick={() => onChange({ from: startOfYear(today), to: today })}
          type="button"
        >
          Cette année
        </button>
        <button
          className="secondary-action"
          onClick={() => onChange({ from: lastTwelveMonths(today), to: today })}
          type="button"
        >
          12 derniers mois
        </button>
      </div>
    </fieldset>
  );
}
