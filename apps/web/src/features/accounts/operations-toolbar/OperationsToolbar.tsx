import { useTranslation } from 'react-i18next';
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
  voidedInHeader = false,
}: {
  value: OperationPeriodFilters;
  onChange: (value: OperationPeriodFilters) => void;
  disabled?: boolean;
  types?: ReactNode;
  instrument?: ReactNode;
  actions?: ReactNode;
  /** The page header owns the voided-operations toggle. */
  voidedInHeader?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <fieldset className={styles.toolbar} disabled={disabled}>
      <legend className="sr-only">{t('accounts.operationsToolbar.legend')}</legend>
      {types}
      <div className={styles.period}>
        <label className={styles.date}>
          <span>{t('accounts.operationsToolbar.from')}</span>
          <input
            type="date"
            value={value.from}
            max={value.to || undefined}
            onChange={(event) => onChange({ ...value, from: event.target.value })}
          />
        </label>
        <label className={styles.date}>
          <span>{t('accounts.operationsToolbar.to')}</span>
          <input
            type="date"
            value={value.to}
            min={value.from || undefined}
            onChange={(event) => onChange({ ...value, to: event.target.value })}
          />
        </label>
      </div>
      {instrument ? <div className={styles.instrument}>{instrument}</div> : null}
      {voidedInHeader ? null : (
        <label className={styles.toggle}>
          <input
            type="checkbox"
            checked={value.includeVoided}
            onChange={(event) => onChange({ ...value, includeVoided: event.target.checked })}
          />
          <span>{t('accounts.operationsToolbar.voided')}</span>
        </label>
      )}
      {actions ? <div className={styles.actions}>{actions}</div> : null}
    </fieldset>
  );
}
