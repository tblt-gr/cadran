import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './BusinessDateField.module.css';

interface BusinessDateFieldProps {
  onChange: (value: string) => void;
  today: string;
  value: string;
}

/**
 * The business date every catalogue rule below is resolved against. It is a
 * deliberate control rather than an implicit "now": a regulated ceiling or rate
 * is chosen by the date of the operation being looked at, not by the day the
 * page happens to be opened.
 */
export function BusinessDateField({ onChange, today, value }: BusinessDateFieldProps) {
  const { t } = useTranslation();
  const fieldId = useId();
  const hintId = useId();

  return (
    <div className={styles.field}>
      <label htmlFor={fieldId}>{t('catalog.asOf.label')}</label>
      <div className={styles.controls}>
        <input
          aria-describedby={hintId}
          id={fieldId}
          max="2100-12-31"
          min="1900-01-01"
          onChange={(event) => onChange(event.target.value)}
          type="date"
          value={value}
        />
        <button
          className="secondary-action"
          disabled={value === today}
          onClick={() => onChange(today)}
          type="button"
        >
          {t('catalog.asOf.today')}
        </button>
      </div>
      <p id={hintId}>{t('catalog.asOf.hint')}</p>
    </div>
  );
}
