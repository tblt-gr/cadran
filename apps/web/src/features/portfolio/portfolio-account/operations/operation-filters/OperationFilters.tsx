import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { type OperationFilterValues } from './operationFilterValues';
import { OperationTypeSelect } from './operation-type-select/OperationTypeSelect';
import styles from './OperationFilters.module.css';

export function OperationFilters({
  currencyCode,
  accountKind,
  disabled = false,
  onChange,
  value,
}: {
  currencyCode: string;
  accountKind?: string;
  disabled?: boolean;
  onChange: (value: OperationFilterValues) => void;
  value: OperationFilterValues;
}) {
  return (
    <fieldset className={styles.filters} disabled={disabled}>
      <legend className="sr-only">Filtres des opérations</legend>
      <div className={styles.row}>
        <OperationTypeSelect
          accountKind={accountKind}
          onChange={(types) => onChange({ ...value, types })}
          value={value.types}
        />
        <InstrumentPicker
          currencyCode={currencyCode}
          label="Instrument"
          onChange={(item) => onChange({ ...value, instrumentId: item?.id ?? null })}
        />
        <label className={styles.date}>
          <span>Du</span>
          <input
            onChange={(event) => onChange({ ...value, from: event.target.value })}
            type="date"
            value={value.from}
          />
        </label>
        <label className={styles.date}>
          <span>Au</span>
          <input
            onChange={(event) => onChange({ ...value, to: event.target.value })}
            type="date"
            value={value.to}
          />
        </label>
        <label className={styles.toggle}>
          <input
            checked={value.includeVoided}
            onChange={(event) => onChange({ ...value, includeVoided: event.target.checked })}
            type="checkbox"
          />
          <span>Afficher les opérations annulées</span>
        </label>
      </div>
    </fieldset>
  );
}
