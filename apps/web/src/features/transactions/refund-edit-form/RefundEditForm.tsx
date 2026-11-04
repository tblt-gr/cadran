import type {
  Account,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import {
  initialTransactionValues,
  transactionRequest,
  validateTransactionValues,
} from '@/features/transactions/transaction-form/transactionFormValues';
import { workspaceToday } from '@/lib/workspaceTime';
import styles from './RefundEditForm.module.css';

interface Props {
  accounts: Account[];
  transaction?: Transaction;
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  submitErrorDetail?: string | null;
}

export function RefundEditForm({
  accounts,
  transaction,
  onSubmit,
  pending,
  submitError,
  submitErrorDetail,
}: Props) {
  const { t } = useTranslation();
  const zone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), zone);
  const [values, setValues] = useState(() =>
    initialTransactionValues(
      transaction ? { ...transaction, nature: 'ADJUSTMENT' } : undefined,
      accounts,
      today,
    ),
  );
  const [showErrors, setShowErrors] = useState(false);
  if (!transaction) return null;
  // Allocation is echoed from the saved transaction, never rebuilt from category defaults.
  const errors = validateTransactionValues(
    { ...values, splitMode: false, categoryId: '' },
    accounts,
    today,
    transaction,
  );
  const eligible = accounts.filter(
    (account) =>
      account.id === transaction.accountId ||
      (account.status === 'ACTIVE' &&
        account.valuationMode !== 'PORTFOLIO' &&
        account.assetCode === transaction.amount.assetCode),
  );
  return (
    <form
      className={styles.form}
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        if (Object.keys(errors).length) {
          setShowErrors(true);
          return;
        }
        onSubmit({
          ...transactionRequest(values, accounts, transaction),
          nature: 'REFUND',
          version: transaction.version,
          userLabel: transaction.userLabel,
          state: transaction.state === 'PENDING' ? 'PENDING' : 'BOOKED',
          amount: transaction.amount,
          categoryId: null,
          splits: transaction.splits.map((split) => ({
            categoryId: split.categoryId,
            amount: split.amount,
            analyticAxes: split.analyticAxes,
            note: split.note,
          })),
        });
      }}
    >
      {submitError ? (
        <TransactionSubmitError
          className={styles.alert}
          accountId={values.accountId}
          kind={submitError}
          detail={submitErrorDetail}
        />
      ) : null}
      <p>{t('transactions.list.refundOf', { label: transaction.refundOriginalLabel })}</p>
      <FormGrid>
        <label>
          <span>{t('transactions.fields.account')}</span>
          <select
            aria-invalid={showErrors && errors.accountId ? true : undefined}
            value={values.accountId}
            onChange={(event) => setValues({ ...values, accountId: event.target.value })}
          >
            {eligible.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
        </label>
        {(['bookedOn', 'rawLabel', 'counterparty', 'note'] as const).map((field) => (
          <label key={field}>
            <span>{t(`transactions.fields.${field === 'rawLabel' ? 'label' : field}`)}</span>
            <input
              type={field === 'bookedOn' ? 'date' : 'text'}
              value={values[field]}
              aria-invalid={showErrors && errors[field] ? true : undefined}
              onChange={(event) => setValues({ ...values, [field]: event.target.value })}
            />
            {showErrors && errors[field] ? (
              <small role="alert">{t(`transactions.validation.${field}`)}</small>
            ) : null}
          </label>
        ))}
        <label>
          <span>{t('transactions.fields.amount')}</span>
          <input disabled value={transaction.amount.value} aria-describedby="refund-fixed-hint" />
        </label>
      </FormGrid>
      <p id="refund-fixed-hint">{t('transactions.form.refundFixed')}</p>
      <FormGrid>
        {transaction.splits.map((split) => (
          <label key={split.id}>
            <span>{split.categoryLabel}</span>
            <input disabled value={split.amount.value} aria-describedby="refund-fixed-hint" />
          </label>
        ))}
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'transactions.form.saving' : 'transactions.form.save')}
        </button>
      </ModalActions>
    </form>
  );
}
