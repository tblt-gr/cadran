import type { Account } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';
import styles from './TransferDestination.module.css';

/** Destination selection and explicit consent to its linked portfolio deposit. */
export function TransferDestination({
  accounts,
  targetAccountId,
  eligible,
  checked,
  disabled,
  invalid,
  onDestinationChange,
  onConsentChange,
}: {
  accounts: Account[];
  targetAccountId: string;
  eligible: boolean;
  checked: boolean;
  disabled: boolean;
  invalid: boolean;
  onDestinationChange: (id: string) => void;
  onConsentChange: (checked: boolean) => void;
}) {
  const { t } = useTranslation();
  const hintId = useId();
  const errorId = useId();
  return (
    <section className={styles.destination}>
      <label>
        <span>{t('transactions.transfer.fields.targetAccount')}</span>
        <select
          disabled={disabled}
          aria-describedby={invalid ? errorId : undefined}
          aria-invalid={invalid ? true : undefined}
          onChange={(event) => onDestinationChange(event.target.value)}
          value={targetAccountId}
        >
          <option value="">{t('transactions.transfer.fields.selectAccount')}</option>
          {accounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.label}
            </option>
          ))}
        </select>
        {invalid ? (
          <small id={errorId}>{t('transactions.transfer.validation.targetAccount')}</small>
        ) : null}
      </label>
      {eligible ? (
        <>
          <label className={formStyles.checkboxLabel}>
            <input
              aria-describedby={hintId}
              checked={checked}
              disabled={disabled}
              onChange={(event) => onConsentChange(event.target.checked)}
              type="checkbox"
            />
            <span>{t('transactions.transfer.depositLabel')}</span>
          </label>
          <small className={formStyles.hint} id={hintId}>
            {t('transactions.transfer.depositHint')}
          </small>
        </>
      ) : null}
    </section>
  );
}
