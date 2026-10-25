import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './PrimaryAccountField.module.css';

interface PrimaryAccountFieldProps {
  checked: boolean;
  eligible: boolean;
  onChange: (checked: boolean) => void;
}

export function PrimaryAccountField({ checked, eligible, onChange }: PrimaryAccountFieldProps) {
  const { t } = useTranslation();
  const hintId = useId();

  return (
    <fieldset className={styles.field}>
      <legend>{t('accounts.primary.legend')}</legend>
      <label>
        <input
          aria-describedby={hintId}
          checked={checked}
          disabled={!eligible}
          onChange={(event) => onChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('accounts.primary.use')}</span>
      </label>
      <small className={styles.hint} id={hintId}>
        {t(eligible ? 'accounts.primary.hint' : 'accounts.primary.ineligible')}{' '}
        {t('accounts.primary.fallback')}
      </small>
    </fieldset>
  );
}
