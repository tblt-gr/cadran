import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import styles from './PositionsTab.module.css';

interface Props {
  asOf: string;
  disabled?: boolean;
  includeClosed: boolean;
  onAsOfChange: (value: string) => void;
  onIncludeClosedChange: (value: boolean) => void;
  today: string;
}

/** Controls shared by the live positions tab and its frozen loading preview. */
export function PositionsToolbar({
  asOf,
  disabled = false,
  includeClosed,
  onAsOfChange,
  onIncludeClosedChange,
  today,
}: Props) {
  return (
    <fieldset className={styles.toolbar} disabled={disabled}>
      <legend className="sr-only">Affichage des positions</legend>
      <BusinessDateField
        hint={null}
        label="Au"
        onChange={onAsOfChange}
        today={today}
        value={asOf}
      />
      <label className={styles.toggle}>
        <input
          checked={includeClosed}
          onChange={(event) => onIncludeClosedChange(event.target.checked)}
          type="checkbox"
        />
        Afficher les positions soldées
      </label>
    </fieldset>
  );
}
