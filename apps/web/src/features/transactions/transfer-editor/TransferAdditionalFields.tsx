import { useTranslation } from 'react-i18next';
import type { TransferFormErrors, TransferFormValues } from './transferFormValues';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';

/** Optional dates, narrative and the fee attached to the outgoing account. */
export function TransferAdditionalFields({
  values,
  errors,
  showErrors,
  disabled,
  sourceAsset,
  set,
}: {
  values: TransferFormValues;
  errors: TransferFormErrors;
  showErrors: boolean;
  disabled: boolean;
  sourceAsset?: string;
  set: <K extends keyof TransferFormValues>(field: K, value: TransferFormValues[K]) => void;
}) {
  const { t } = useTranslation();
  return (
    <>
      <label>
        <span>{t('transactions.transfer.fields.valueOn')}</span>
        <input
          disabled={disabled}
          aria-invalid={showErrors && errors.valueOn ? true : undefined}
          onChange={(event) => set('valueOn', event.target.value)}
          type="date"
          value={values.valueOn}
        />
      </label>

      <label>
        <span>{t('transactions.transfer.fields.label')}</span>
        <input
          disabled={disabled}
          aria-invalid={showErrors && errors.label ? true : undefined}
          onChange={(event) => set('label', event.target.value)}
          value={values.label}
        />
        {showErrors && errors.label ? (
          <small>{t('transactions.transfer.validation.label')}</small>
        ) : null}
      </label>

      <label>
        <span>{t('transactions.transfer.fields.note')}</span>
        <input
          disabled={disabled}
          onChange={(event) => set('note', event.target.value)}
          value={values.note}
        />
      </label>

      <div>
        <label className={formStyles.checkboxLabel}>
          <input
            disabled={disabled}
            checked={values.hasFee}
            onChange={(event) => set('hasFee', event.target.checked)}
            type="checkbox"
          />
          <span>{t('transactions.transfer.fields.hasFee')}</span>
        </label>
        {values.hasFee ? (
          <label>
            <span>{withAssetCode(t('transactions.transfer.fields.fee'), sourceAsset)}</span>
            <input
              disabled={disabled}
              aria-invalid={showErrors && errors.feeValue ? true : undefined}
              autoComplete="off"
              inputMode="decimal"
              onChange={(event) => set('feeValue', event.target.value)}
              spellCheck={false}
              value={values.feeValue}
            />
            {showErrors && errors.feeValue ? (
              <small>{t('transactions.transfer.validation.fee')}</small>
            ) : null}
          </label>
        ) : null}
      </div>
    </>
  );
}

function withAssetCode(label: string, assetCode: string | undefined): string {
  return assetCode === undefined ? label : `${label} (${assetCode})`;
}
