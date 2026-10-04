import { MonthEndPicker } from '@/features/portfolio/month-end/month-end-picker/MonthEndPicker';
import styles from './ValuationsTab.module.css';

/** Explanation and the recording actions; disabled while the account or list is pending. */
export function ValuationsToolbar({
  disabled,
  dueMonths,
  onCreate,
  onMonthEnd,
}: {
  disabled: boolean;
  /** Null while the due months load. */
  dueMonths: readonly string[] | null;
  onCreate: () => void;
  onMonthEnd: (month: string) => void;
}) {
  return (
    <div className={styles.toolbar}>
      <p className={styles.note}>
        Une valorisation fige la valeur du compte à une date, d’après un relevé. Elle est publiée
        dans le patrimoine lorsqu’elle est complète.
      </p>
      <div className={styles.actions}>
        <MonthEndPicker disabled={disabled} dueMonths={dueMonths} onPick={onMonthEnd} />
        <button className="secondary-action" disabled={disabled} onClick={onCreate} type="button">
          Enregistrer une valorisation
        </button>
      </div>
    </div>
  );
}
