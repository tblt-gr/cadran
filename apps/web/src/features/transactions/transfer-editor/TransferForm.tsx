import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { Account, CreateTransferRequest } from '@cadran/api-client';
import { useId, useState } from 'react';
import { TransferAdditionalFields } from './TransferAdditionalFields';
import { TransferAccountField } from './TransferAccountField';
import { balancesAfterTransfer } from './balancesAfterTransfer';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { workspaceToday } from '@/lib/workspaceTime';
import {
  isCrossAssetTransfer,
  transferRequest,
  validateTransferValues,
} from './transferFormValues';
import type { TransferFormDefaults } from './initialTransferFormValues';
import { useTransferFormValues } from './useTransferFormValues';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';

export type { TransferFormDefaults } from './initialTransferFormValues';

interface TransferFormProps {
  accounts: Account[];
  defaults?: TransferFormDefaults;
  onSubmit: (body: CreateTransferRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
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
  const errorId = useId();
  const workspaceTimeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), workspaceTimeZone);
  const lockedSourceAccountId = defaults?.lockedSourceAccountId;
  const { values, set } = useTransferFormValues(accounts, today, defaults);
  const destinationAccounts = lockedSourceAccountId
    ? accounts.filter(
        (account) => account.status === 'ACTIVE' && account.id !== lockedSourceAccountId,
      )
    : accounts;
  const destination = accounts.find((account) => account.id === values.targetAccountId);
  const source = accounts.find((account) => account.id === values.sourceAccountId);
  const [showErrors, setShowErrors] = useState(false);
  const errors = validateTransferValues(values, accounts, today);
  const crossAsset = isCrossAssetTransfer(values, accounts);
  const { sourceAfter, targetAfter } = balancesAfterTransfer({
    crossAsset,
    destination,
    errors,
    source,
    values,
  });
  const sourceAsset = accounts.find((account) => account.id === values.sourceAccountId)?.assetCode;
  const targetAsset = accounts.find((account) => account.id === values.targetAccountId)?.assetCode;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (pending) return;
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      return;
    }

    onSubmit(transferRequest(values, accounts));
  }

  return (
    <ModalForm className={formStyles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <TransactionSubmitError
          accountId={values.sourceAccountId}
          className={formStyles.alert}
          kind={submitError}
        />
      ) : null}

      <FormGrid>
        <TransferAccountField
          accounts={accounts}
          after={sourceAfter}
          autoFocus={!lockedSourceAccountId}
          disabled={pending || Boolean(lockedSourceAccountId)}
          error={showErrors && errors.sourceAccountId}
          errorId={`${errorId}-sourceAccountId`}
          errorLabel={t('transactions.transfer.validation.sourceAccount')}
          label={t('transactions.transfer.fields.sourceAccount')}
          onChange={(id) => set('sourceAccountId', id)}
          value={values.sourceAccountId}
        />

        <TransferAccountField
          accounts={destinationAccounts}
          after={targetAfter}
          autoFocus={Boolean(lockedSourceAccountId)}
          disabled={pending}
          error={showErrors && errors.targetAccountId}
          errorId={`${errorId}-targetAccountId`}
          errorLabel={t('transactions.transfer.validation.targetAccount')}
          label={t('transactions.transfer.fields.targetAccount')}
          onChange={(id) => set('targetAccountId', id)}
          value={values.targetAccountId}
        />

        <label>
          <span>{t('transactions.transfer.fields.label')}</span>
          <input
            disabled={pending}
            aria-describedby={showErrors && errors.label ? `${errorId}-label` : undefined}
            aria-invalid={showErrors && errors.label ? true : undefined}
            onChange={(event) => set('label', event.target.value)}
            value={values.label}
          />
          {showErrors && errors.label ? (
            <small id={`${errorId}-label`}>{t('transactions.transfer.validation.label')}</small>
          ) : null}
        </label>

        <label>
          <span>{withAssetCode(t('transactions.transfer.fields.sourceAmount'), sourceAsset)}</span>
          <input
            disabled={pending}
            aria-describedby={
              showErrors && errors.sourceAmountValue ? `${errorId}-sourceAmountValue` : undefined
            }
            aria-invalid={showErrors && errors.sourceAmountValue ? true : undefined}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => set('sourceAmountValue', event.target.value)}
            spellCheck={false}
            value={values.sourceAmountValue}
          />
          {showErrors && errors.sourceAmountValue ? (
            <small id={`${errorId}-sourceAmountValue`}>
              {t('transactions.transfer.validation.sourceAmount')}
            </small>
          ) : null}
        </label>

        {crossAsset ? (
          <label>
            <span>
              {withAssetCode(t('transactions.transfer.fields.targetAmount'), targetAsset)}
            </span>
            <input
              disabled={pending}
              aria-describedby={
                showErrors && errors.targetAmountValue ? `${errorId}-targetAmountValue` : undefined
              }
              aria-invalid={showErrors && errors.targetAmountValue ? true : undefined}
              autoComplete="off"
              inputMode="decimal"
              onChange={(event) => set('targetAmountValue', event.target.value)}
              spellCheck={false}
              value={values.targetAmountValue}
            />
            {showErrors && errors.targetAmountValue ? (
              <small id={`${errorId}-targetAmountValue`}>
                {t('transactions.transfer.validation.targetAmount')}
              </small>
            ) : null}
          </label>
        ) : null}

        <label>
          <span>{t('transactions.transfer.fields.bookedOn')}</span>
          <input
            disabled={pending}
            aria-describedby={showErrors && errors.bookedOn ? `${errorId}-bookedOn` : undefined}
            aria-invalid={showErrors && errors.bookedOn ? true : undefined}
            onChange={(event) => set('bookedOn', event.target.value)}
            type="date"
            value={values.bookedOn}
          />
          {showErrors && errors.bookedOn ? (
            <small id={`${errorId}-bookedOn`}>
              {t('transactions.transfer.validation.bookedOn')}
            </small>
          ) : null}
        </label>

        <TransferAdditionalFields
          values={values}
          errors={errors}
          showErrors={showErrors}
          disabled={pending}
          sourceAsset={sourceAsset}
          set={set}
        />
      </FormGrid>

      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'transactions.transfer.saving' : 'transactions.transfer.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}

/** Every decimal travels with its asset code, so the label names it too, once an account is picked. */
function withAssetCode(label: string, assetCode: string | undefined): string {
  return assetCode === undefined ? label : `${label} (${assetCode})`;
}
