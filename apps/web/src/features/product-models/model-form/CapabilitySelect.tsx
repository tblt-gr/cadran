import type { AccountValuationMode, ProductCapability } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CAPABILITIES, valuationRequiredCapability } from './modelFormValues';
import styles from './CapabilitySelect.module.css';

interface CapabilitySelectProps {
  value: ProductCapability[];
  isLiability: boolean;
  valuationMode: AccountValuationMode;
  invalid: boolean;
  onChange: (capabilities: ProductCapability[]) => void;
}

/**
 * The behaviours a model activates. It is the public contract a screen reads
 * instead of guessing from a name. Two are not free choices: the capability the
 * valuation mode consumes is kept on, and `SUPPORTS_LIABILITY` is bound to a
 * liability family — on for one, unavailable for the others — so the model
 * cannot claim a side of the balance sheet its family denies.
 */
export function CapabilitySelect({
  value,
  isLiability,
  valuationMode,
  invalid,
  onChange,
}: CapabilitySelectProps) {
  const { t } = useTranslation();
  const required = valuationRequiredCapability(valuationMode);

  function toggle(capability: ProductCapability, checked: boolean) {
    onChange(checked ? [...value, capability] : value.filter((entry) => entry !== capability));
  }

  return (
    <fieldset className={styles.capabilities} aria-invalid={invalid ? true : undefined}>
      <legend>{t('productModels.form.capabilities')}</legend>
      <div className={styles.grid}>
        {CAPABILITIES.map((capability) => {
          // The valuation mode's capability and SUPPORTS_LIABILITY are both
          // decided by other fields, so they are shown but not toggled here.
          const locked = capability === required || capability === 'SUPPORTS_LIABILITY';
          const checked =
            capability === 'SUPPORTS_LIABILITY'
              ? isLiability
              : capability === required || value.includes(capability);

          return (
            <label key={capability}>
              <input
                checked={checked}
                disabled={locked}
                onChange={(event) => toggle(capability, event.target.checked)}
                type="checkbox"
              />
              <span>{t(`catalog.capabilities.items.${capability}`)}</span>
            </label>
          );
        })}
      </div>
      {invalid ? (
        <small className={styles.error} role="alert">
          {t('productModels.form.errors.capabilities')}
        </small>
      ) : (
        <small className={styles.hint}>{t('productModels.form.capabilitiesHint')}</small>
      )}
    </fieldset>
  );
}
