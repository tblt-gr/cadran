import type { AccountReconciliation, AccountReconciliationResolution } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import styles from './ResolutionForm.module.css';

const CHOICES: AccountReconciliationResolution[] = ['MATCH', 'OVERRIDE', 'ADJUST'];

interface ResolutionFormProps {
  pending: boolean;
  reconciliation: AccountReconciliation;
  onSubmit: (resolution: AccountReconciliationResolution) => void;
}

/**
 * Chooses how the closing balance is reconciled. Only the resolutions the
 * server offers can be picked; accepting a non-zero discrepancy needs an
 * explicit confirmation naming what it does.
 */
export function ResolutionForm({ pending, reconciliation, onSubmit }: ResolutionFormProps) {
  const { i18n, t } = useTranslation();
  const [choice, setChoice] = useState<AccountReconciliationResolution | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const offered = new Set(reconciliation.availableResolutions);
  const discrepancy = reconciliation.discrepancy;

  if (offered.size === 0) {
    return null;
  }

  const ready = choice !== null && (choice !== 'OVERRIDE' || confirmed);

  function submit(event: FormEvent) {
    event.preventDefault();
    if (choice !== null && ready) {
      onSubmit(choice);
    }
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <fieldset className={styles.choices}>
        <legend>{t('accounts.reconciliation.resolution.legend')}</legend>
        {CHOICES.map((value) => (
          <label className={styles.choice} key={value}>
            <input
              checked={choice === value}
              disabled={!offered.has(value) || pending}
              name="reconciliation-resolution"
              onChange={() => {
                setChoice(value);
                setConfirmed(false);
              }}
              type="radio"
              value={value}
            />
            <span>
              {t(`accounts.reconciliation.resolution.${value}.label`)}
              <small>{t(`accounts.reconciliation.resolution.${value}.hint`)}</small>
            </span>
          </label>
        ))}
      </fieldset>

      {choice === 'ADJUST' && discrepancy !== null ? (
        <p className={styles.note}>
          {t('accounts.reconciliation.resolution.adjustNote', {
            amount: formatAmount(discrepancy.value, discrepancy.assetCode, i18n.language),
          })}
        </p>
      ) : null}

      {choice === 'OVERRIDE' ? (
        <label className={styles.confirm}>
          <input
            checked={confirmed}
            onChange={(event) => setConfirmed(event.target.checked)}
            type="checkbox"
          />
          <span>{t('accounts.reconciliation.resolution.overrideConfirm')}</span>
        </label>
      ) : null}

      <div className={styles.actions}>
        <button className="primary-action" disabled={!ready || pending} type="submit">
          {t(pending ? 'accounts.reconciliation.reconciling' : 'accounts.reconciliation.reconcile')}
        </button>
      </div>
    </form>
  );
}
