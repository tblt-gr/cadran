import type { Account, CreateTransferRequest } from '@cadran/api-client';
import { useId, useState } from 'react';
import { TransferAdditionalFields } from './TransferAdditionalFields';
import { TransferDestination } from './TransferDestination';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { workspaceToday } from '@/lib/workspaceTime';
import {
  operationFieldErrors,
  operationSaveMessage,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import {
  initialTransferValues,
  isCrossAssetTransfer,
  transferRequest,
  validateTransferValues,
  type TransferFormValues,
} from './transferFormValues';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';

interface TransferFormProps {
  accounts: Account[];
  defaults?: TransferFormDefaults;
  onSubmit: (body: CreateTransferRequest, createDeposit: boolean) => void;
  onRetryDeposit: () => void;
  transferCreated: boolean;
  depositError: unknown;
  pending: boolean;
  submitError: TransactionErrorKind | null;
}

export interface TransferFormDefaults {
  bookedOn: string;
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
  onRetryDeposit,
  transferCreated,
  depositError,
  pending,
  submitError,
}: TransferFormProps) {
  const { t } = useTranslation();
  const errorId = useId();
  const workspaceTimeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), workspaceTimeZone);
  const [values, setValues] = useState(() => {
    const initial = initialTransferValues(accounts, defaults?.bookedOn ?? today);

    const primary = accounts.find((account) => account.isPrimary && account.status === 'ACTIVE');

    return defaults
      ? {
          ...initial,
          bookedOn: defaults.bookedOn,
          sourceAccountId: primary && primary.id !== defaults.targetAccountId ? primary.id : '',
          targetAccountId: defaults.targetAccountId,
        }
      : initial;
  });
  const [consentTarget, setConsentTarget] = useState<string | null>(null);
  const destination = accounts.find((account) => account.id === values.targetAccountId);
  const source = accounts.find((account) => account.id === values.sourceAccountId);
  const eligible =
    destination?.kind === 'PORTFOLIO' &&
    destination.valuationMode === 'PORTFOLIO' &&
    destination.status === 'ACTIVE' &&
    source?.status === 'ACTIVE' &&
    source.id !== destination.id &&
    source.assetCode === destination.assetCode;
  if (!eligible && consentTarget !== null) setConsentTarget(null);
  const checked = eligible && consentTarget === destination.id;
  const depositMessage =
    operationSaveMessage(depositError) ?? Object.values(operationFieldErrors(depositError))[0];
  const [showErrors, setShowErrors] = useState(false);
  const errors = validateTransferValues(values, accounts, today);
  const crossAsset = isCrossAssetTransfer(values, accounts);
  const sourceAsset = accounts.find((account) => account.id === values.sourceAccountId)?.assetCode;
  const targetAsset = accounts.find((account) => account.id === values.targetAccountId)?.assetCode;

  function set<K extends keyof TransferFormValues>(field: K, value: TransferFormValues[K]) {
    if (field === 'targetAccountId' || field === 'sourceAccountId') setConsentTarget(null);
    setValues((current) => ({ ...current, [field]: value }));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (pending) return;
    if (transferCreated) {
      onRetryDeposit();
      return;
    }
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      return;
    }

    onSubmit(transferRequest(values, accounts), checked);
  }

  return (
    <form className={formStyles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <TransactionSubmitError
          accountId={values.sourceAccountId}
          className={formStyles.alert}
          kind={submitError}
        />
      ) : null}

      {transferCreated && depositError ? (
        <div className={formStyles.alert} role="alert">
          <p>{t('transactions.transfer.depositPartial')}</p>
          <p>{depositMessage}</p>
        </div>
      ) : null}
      <p>{t('transactions.transfer.notice')}</p>

      <div className={formStyles.fields}>
        <label>
          <span>{t('transactions.transfer.fields.sourceAccount')}</span>
          <select
            disabled={pending || transferCreated}
            aria-describedby={
              showErrors && errors.sourceAccountId ? `${errorId}-sourceAccountId` : undefined
            }
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
            <small id={`${errorId}-sourceAccountId`}>
              {t('transactions.transfer.validation.sourceAccount')}
            </small>
          ) : null}
        </label>

        <TransferDestination
          accounts={accounts}
          targetAccountId={values.targetAccountId}
          eligible={eligible}
          checked={checked}
          disabled={pending || transferCreated}
          invalid={showErrors && Boolean(errors.targetAccountId)}
          onDestinationChange={(id) => set('targetAccountId', id)}
          onConsentChange={(consent) => setConsentTarget(consent ? values.targetAccountId : null)}
        />

        <label>
          <span>{withAssetCode(t('transactions.transfer.fields.sourceAmount'), sourceAsset)}</span>
          <input
            disabled={pending || transferCreated}
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
              disabled={pending || transferCreated}
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
            disabled={pending || transferCreated}
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
          disabled={pending || transferCreated}
          sourceAsset={sourceAsset}
          set={set}
        />
      </div>

      <div className={formStyles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(
            pending
              ? 'transactions.transfer.saving'
              : transferCreated
                ? 'transactions.transfer.retryDeposit'
                : 'transactions.transfer.save',
          )}
        </button>
      </div>
    </form>
  );
}

/** Every decimal travels with its asset code, so the label names it too, once an account is picked. */
function withAssetCode(label: string, assetCode: string | undefined): string {
  return assetCode === undefined ? label : `${label} (${assetCode})`;
}
