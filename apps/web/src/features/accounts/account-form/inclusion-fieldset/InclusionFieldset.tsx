import { useTranslation } from 'react-i18next';
import styles from './InclusionFieldset.module.css';

interface InclusionFieldsetProps {
  includeInEmergencyFund: boolean;
  includeInNetWorth: boolean;
  onEmergencyFundChange: (checked: boolean) => void;
  onNetWorthChange: (checked: boolean) => void;
}

/**
 * The two policies that decide what an account counts towards. The emergency
 * fund is nested under net worth on purpose: an account left out of net worth
 * cannot back it, and the backend refuses that combination outright.
 */
export function InclusionFieldset({
  includeInEmergencyFund,
  includeInNetWorth,
  onEmergencyFundChange,
  onNetWorthChange,
}: InclusionFieldsetProps) {
  const { t } = useTranslation();

  return (
    <fieldset className={styles.inclusion}>
      <legend>{t('accounts.fields.inclusion')}</legend>
      <label>
        <input
          checked={includeInNetWorth}
          onChange={(event) => onNetWorthChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('accounts.fields.includeInNetWorth')}</span>
      </label>
      <label>
        <input
          checked={includeInEmergencyFund}
          disabled={!includeInNetWorth}
          onChange={(event) => onEmergencyFundChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('accounts.fields.includeInEmergencyFund')}</span>
      </label>
      {!includeInNetWorth ? (
        <small className={styles.hint}>{t('accounts.form.emergencyFundHint')}</small>
      ) : null}
    </fieldset>
  );
}
