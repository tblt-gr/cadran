import type { Account, CreateTransferRequest } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { workspaceToday } from '@/lib/workspaceTime';
import {
  initialTransferValues,
  isCrossAssetTransfer,
  transferRequest,
  validateTransferValues,
  type TransferFormValues,
} from './transferFormValues';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';

interface TransferFormProps {
  accounts: Account[];
  defaults?: TransferFormDefaults;
  onSubmit: (body: CreateTransferRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
}

export interface TransferFormDefaults {
  bookedOn: string;
  requireSourceChoice: boolean;
  targetAccountId: string;
}

/**
 * Creation only: the two accounts and both leg amounts are fixed once the
 * transfer exists, exactly like a plain transaction's asset and account, so
 * this form never receives an existing transfer to edit.
 */
export function TransferForm({
  accounts,
  defaults,
  onSubmit,
  pending,
  submitError,
}: TransferFormProps) {
  const { t } = useTranslation();
  const workspaceTimeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), workspaceTimeZone);
  const [values, setValues] = useState(() => {
    const initial = initialTransferValues(accounts, defaults?.bookedOn ?? today);

    return defaults
      ? {
          ...initial,
          bookedOn: defaults.bookedOn,
          sourceAccountId: defaults.requireSourceChoice ? '' : initial.sourceAccountId,
          targetAccountId: defaults.targetAccountId,
        }
      : initial;
  });
  const [showErrors, setShowErrors] = useState(false);
  const errors = validateTransferValues(values, accounts, today);
  const crossAsset = isCrossAssetTransfer(values, accounts);
  const sourceAsset = accounts.find((account) => account.id === values.sourceAccountId)?.assetCode;
  const targetAsset = accounts.find((account) => account.id === values.targetAccountId)?.assetCode;

  function set<K extends keyof TransferFormValues>(field: K, value: TransferFormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      return;
    }

    onSubmit(transferRequest(values, accounts));
  }

  return (
    <form className={formStyles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={formStyles.alert} role="alert">
          {t(`transactions.errors.${submitError}`)}
        </p>
      ) : null}

      <p>{t('transactions.transfer.notice')}</p>

      <div className={formStyles.fields}>
        <label>
          <span>{t('transactions.transfer.fields.sourceAccount')}</span>
          <select
            aria-invalid={showErrors && errors.sourceAccountId ? true : undefined}
            data-autofocus
            onChange={(event) => set('sourceAccountId', event.target.value)}
            value={values.sourceAccountId}
          >
            <option value="">{t('transactions.transfer.fields.selectAccount')}</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
          {showErrors && errors.sourceAccountId ? (
            <small>{t('transactions.transfer.validation.sourceAccount')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.transfer.fields.targetAccount')}</span>
          <select
            aria-invalid={showErrors && errors.targetAccountId ? true : undefined}
            onChange={(event) => set('targetAccountId', event.target.value)}
            value={values.targetAccountId}
          >
            <option value="">{t('transactions.transfer.fields.selectAccount')}</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
          {showErrors && errors.targetAccountId ? (
            <small>{t('transactions.transfer.validation.targetAccount')}</small>
          ) : null}
        </label>

        <label>
          <span>{withAssetCode(t('transactions.transfer.fields.sourceAmount'), sourceAsset)}</span>
          <input
            aria-invalid={showErrors && errors.sourceAmountValue ? true : undefined}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => set('sourceAmountValue', event.target.value)}
            spellCheck={false}
            value={values.sourceAmountValue}
          />
          {showErrors && errors.sourceAmountValue ? (
            <small>{t('transactions.transfer.validation.sourceAmount')}</small>
          ) : null}
        </label>

        {crossAsset ? (
          <label>
            <span>
              {withAssetCode(t('transactions.transfer.fields.targetAmount'), targetAsset)}
            </span>
            <input
              aria-invalid={showErrors && errors.targetAmountValue ? true : undefined}
              autoComplete="off"
              inputMode="decimal"
              onChange={(event) => set('targetAmountValue', event.target.value)}
              spellCheck={false}
              value={values.targetAmountValue}
            />
            {showErrors && errors.targetAmountValue ? (
              <small>{t('transactions.transfer.validation.targetAmount')}</small>
            ) : null}
          </label>
        ) : null}

        <label>
          <span>{t('transactions.transfer.fields.bookedOn')}</span>
          <input
            aria-invalid={showErrors && errors.bookedOn ? true : undefined}
            onChange={(event) => set('bookedOn', event.target.value)}
            type="date"
            value={values.bookedOn}
          />
          {showErrors && errors.bookedOn ? (
            <small>{t('transactions.transfer.validation.bookedOn')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.transfer.fields.valueOn')}</span>
          <input
            aria-invalid={showErrors && errors.valueOn ? true : undefined}
            onChange={(event) => set('valueOn', event.target.value)}
            type="date"
            value={values.valueOn}
          />
        </label>

        <label>
          <span>{t('transactions.transfer.fields.label')}</span>
          <input
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
          <input onChange={(event) => set('note', event.target.value)} value={values.note} />
        </label>

        <div>
          <label className={formStyles.checkboxLabel}>
            <input
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
      </div>

      <div className={formStyles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'transactions.transfer.saving' : 'transactions.transfer.save')}
        </button>
      </div>
    </form>
  );
}

/** Every decimal travels with its asset code, so the label names it too, once an account is picked. */
function withAssetCode(label: string, assetCode: string | undefined): string {
  return assetCode === undefined ? label : `${label} (${assetCode})`;
}
