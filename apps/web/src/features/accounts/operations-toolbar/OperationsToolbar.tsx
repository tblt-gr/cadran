import type { ReactNode } from 'react';
import styles from './OperationsToolbar.module.css';

export interface OperationPeriodFilters {
  from: string;
  to: string;
  includeVoided: boolean;
}

export function OperationsToolbar({
  value,
  onChange,
  disabled = false,
  types,
  instrument,
  actions,
}: {
  value: OperationPeriodFilters;
  onChange: (value: OperationPeriodFilters) => void;
  disabled?: boolean;
  types?: ReactNode;
  instrument?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <fieldset className={styles.toolbar} disabled={disabled}>
      <legend className="sr-only">Filtres des opérations</legend>
      {types}
      <div className={styles.period}>
        <label className={styles.date}>
          <span>Du</span>
          <input
            type="date"
            value={value.from}
            max={value.to || undefined}
            onChange={(event) => onChange({ ...value, from: event.target.value })}
          />
        </label>
        <label className={styles.date}>
          <span>Au</span>
          <input
            type="date"
            value={value.to}
            min={value.from || undefined}
            onChange={(event) => onChange({ ...value, to: event.target.value })}
          />
        </label>
      </div>
      {instrument ? <div className={styles.instrument}>{instrument}</div> : null}
      <label className={styles.toggle}>
        <input
          type="checkbox"
          checked={value.includeVoided}
          onChange={(event) => onChange({ ...value, includeVoided: event.target.checked })}
        />
        <span>Afficher les opérations annulées</span>
      </label>
      {actions ? <div className={styles.actions}>{actions}</div> : null}
    </fieldset>
  );
}
