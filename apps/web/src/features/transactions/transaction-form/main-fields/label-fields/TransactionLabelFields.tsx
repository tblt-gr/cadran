import { useTranslation } from 'react-i18next';
import type {
  TransactionFormErrors,
  TransactionFormValues,
} from '@/features/transactions/transaction-form/transactionFormValues';
import styles from './TransactionLabelFields.module.css';

interface TransactionLabelFieldsProps {
  /** Prefix of the ids the error messages are described by. */
  errorId: string;
  errors: TransactionFormErrors;
  /** The bank label is kept as imported: only the display label is editable. */
  locked: boolean;
  showErrors: boolean;
  values: TransactionFormValues;
  onChange: <K extends keyof TransactionFormValues>(
    field: K,
    value: TransactionFormValues[K],
  ) => void;
}

export function TransactionLabelFields({
  errorId,
  errors,
  locked,
  showErrors,
  values,
  onChange,
}: TransactionLabelFieldsProps) {
  const { t } = useTranslation();

  if (!locked) {
    return (
      <label>
        <span>{t('transactions.fields.label')}</span>
        <input
          aria-describedby={showErrors && errors.rawLabel ? `${errorId}-rawLabel` : undefined}
          aria-invalid={showErrors && errors.rawLabel ? true : undefined}
          aria-label={t('transactions.fields.label')}
          data-autofocus
          onChange={(event) => onChange('rawLabel', event.target.value)}
          value={values.rawLabel}
        />
        {showErrors && errors.rawLabel ? (
          <small id={`${errorId}-rawLabel`}>{t('transactions.validation.rawLabel')}</small>
        ) : null}
      </label>
    );
  }

  return (
    <>
      <label>
        <span>{t('transactions.fields.displayLabel')}</span>
        <input
          aria-describedby={showErrors && errors.userLabel ? `${errorId}-userLabel` : undefined}
          aria-invalid={showErrors && errors.userLabel ? true : undefined}
          aria-label={t('transactions.fields.displayLabel')}
          data-autofocus
          onChange={(event) => onChange('userLabel', event.target.value)}
          value={values.userLabel}
        />
        {showErrors && errors.userLabel ? (
          <small id={`${errorId}-userLabel`}>{t('transactions.validation.userLabel')}</small>
        ) : null}
      </label>
      <label>
        <span>{t('transactions.fields.rawLabel')}</span>
        <input
          aria-describedby="transaction-raw-label-hint"
          aria-label={t('transactions.fields.rawLabel')}
          readOnly
          value={values.rawLabel}
        />
        <small className={styles.hint} id="transaction-raw-label-hint">
          {t('transactions.form.rawLabelLocked')}
        </small>
      </label>
    </>
  );
}
