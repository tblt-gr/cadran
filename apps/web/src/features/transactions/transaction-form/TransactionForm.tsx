import type {
  Account,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import {
  categoryTypeForAmount,
  initialTransactionValues,
  transactionRequest,
  validateTransactionValues,
  type TransactionFormValues,
} from './transactionFormValues';
import { TransactionCategoryField } from './transaction-category-field/TransactionCategoryField';
import styles from './TransactionForm.module.css';

const NATURES: TransactionFormValues['nature'][] = ['EXPENSE', 'INCOME', 'FEE', 'ADJUSTMENT'];
const PAYMENT_METHODS: Array<NonNullable<CreateTransactionRequest['paymentMethod']>> = [
  'CARD',
  'TRANSFER',
  'DIRECT_DEBIT',
  'CHECK',
  'CASH',
  'OTHER',
];

interface TransactionFormProps {
  accounts: Account[];
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  transaction?: Transaction;
}

export function TransactionForm({
  accounts,
  onSubmit,
  pending,
  submitError,
  transaction,
}: TransactionFormProps) {
  const { t } = useTranslation();
  const [values, setValues] = useState(() => initialTransactionValues(transaction, accounts));
  const [showErrors, setShowErrors] = useState(false);
  const resolved =
    values.accountId === '' && accounts[0] !== undefined
      ? { ...values, accountId: accounts[0].id }
      : values;
  const errors = validateTransactionValues(resolved, accounts, transaction);
  const editing = transaction !== undefined;
  const stateLocked = editing && transaction.state !== 'PENDING';
  const rawLabelLocked = editing && transaction.source !== 'MANUAL';
  const categoryType = categoryTypeForAmount(values.amountValue, values.nature);

  function set<K extends keyof TransactionFormValues>(field: K, value: TransactionFormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function setAmount(value: string) {
    setValues((current) => ({
      ...current,
      amountValue: value,
      categoryId:
        categoryTypeForAmount(current.amountValue, current.nature) ===
        categoryTypeForAmount(value, current.nature)
          ? current.categoryId
          : '',
    }));
  }

  function setNature(value: TransactionFormValues['nature']) {
    setValues((current) => ({
      ...current,
      nature: value,
      categoryId:
        categoryTypeForAmount(current.amountValue, current.nature) ===
        categoryTypeForAmount(current.amountValue, value)
          ? current.categoryId
          : '',
    }));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      return;
    }

    onSubmit(transactionRequest(resolved, accounts, transaction));
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`transactions.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <label>
          <span>{t('transactions.fields.account')}</span>
          <select
            aria-describedby={editing ? 'transaction-account-hint' : undefined}
            aria-invalid={showErrors && errors.accountId ? true : undefined}
            aria-label={t('transactions.fields.account')}
            disabled={editing}
            onChange={(event) => set('accountId', event.target.value)}
            value={resolved.accountId}
          >
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
          {showErrors && errors.accountId ? (
            <small>{t('transactions.validation.account')}</small>
          ) : null}
          {editing ? (
            <small className={styles.hint} id="transaction-account-hint">
              {t('transactions.form.accountLocked')}
            </small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.bookedOn')}</span>
          <input
            aria-invalid={showErrors && errors.bookedOn ? true : undefined}
            onChange={(event) => set('bookedOn', event.target.value)}
            type="date"
            value={values.bookedOn}
          />
          {showErrors && errors.bookedOn ? (
            <small>{t('transactions.validation.bookedOn')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.valueOn')}</span>
          <input
            aria-invalid={showErrors && errors.valueOn ? true : undefined}
            onChange={(event) => set('valueOn', event.target.value)}
            type="date"
            value={values.valueOn}
          />
          {showErrors && errors.valueOn ? (
            <small>{t('transactions.validation.valueOn')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.authorizedOn')}</span>
          <input
            aria-invalid={showErrors && errors.authorizedOn ? true : undefined}
            onChange={(event) => set('authorizedOn', event.target.value)}
            type="date"
            value={values.authorizedOn}
          />
          {showErrors && errors.authorizedOn ? (
            <small>{t('transactions.validation.authorizedOn')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.amount')}</span>
          <input
            aria-invalid={showErrors && errors.amountValue ? true : undefined}
            autoComplete="off"
            data-autofocus
            inputMode="decimal"
            onChange={(event) => setAmount(event.target.value)}
            spellCheck={false}
            value={values.amountValue}
          />
          {showErrors && errors.amountValue ? (
            <small>{t('transactions.validation.amount')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.nature')}</span>
          <select
            aria-invalid={showErrors && errors.nature ? true : undefined}
            onChange={(event) => setNature(event.target.value as TransactionFormValues['nature'])}
            value={values.nature}
          >
            {NATURES.map((nature) => (
              <option key={nature} value={nature}>
                {t(`transactions.natures.${nature}`)}
              </option>
            ))}
          </select>
          {showErrors && errors.nature ? (
            <small>{t('transactions.validation.nature')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.state')}</span>
          <select
            aria-describedby={stateLocked ? 'transaction-state-hint' : undefined}
            aria-label={t('transactions.fields.state')}
            disabled={stateLocked}
            onChange={(event) => set('state', event.target.value as TransactionFormValues['state'])}
            value={values.state}
          >
            <option value="PENDING">{t('transactions.states.PENDING')}</option>
            <option value="BOOKED">{t('transactions.states.BOOKED')}</option>
            {editing && transaction.state === 'PENDING' ? (
              <option value="REJECTED">{t('transactions.states.REJECTED')}</option>
            ) : null}
          </select>
          {stateLocked ? (
            <small className={styles.hint} id="transaction-state-hint">
              {t('transactions.form.stateLocked')}
            </small>
          ) : null}
        </label>

        <label>
          <span>
            {t(rawLabelLocked ? 'transactions.fields.rawLabel' : 'transactions.fields.label')}
          </span>
          <input
            aria-describedby={rawLabelLocked ? 'transaction-raw-label-hint' : undefined}
            aria-invalid={showErrors && errors.rawLabel ? true : undefined}
            aria-label={t(
              rawLabelLocked ? 'transactions.fields.rawLabel' : 'transactions.fields.label',
            )}
            onChange={(event) => set('rawLabel', event.target.value)}
            readOnly={rawLabelLocked}
            value={values.rawLabel}
          />
          {showErrors && errors.rawLabel ? (
            <small>{t('transactions.validation.rawLabel')}</small>
          ) : null}
          {rawLabelLocked ? (
            <small className={styles.hint} id="transaction-raw-label-hint">
              {t('transactions.form.rawLabelLocked')}
            </small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.counterparty')}</span>
          <input
            aria-invalid={showErrors && errors.counterparty ? true : undefined}
            onChange={(event) => set('counterparty', event.target.value)}
            value={values.counterparty}
          />
          {showErrors && errors.counterparty ? (
            <small>{t('transactions.validation.counterparty')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('transactions.fields.note')}</span>
          <input
            aria-invalid={showErrors && errors.note ? true : undefined}
            onChange={(event) => set('note', event.target.value)}
            value={values.note}
          />
          {showErrors && errors.note ? <small>{t('transactions.validation.note')}</small> : null}
        </label>

        <label>
          <span>{t('transactions.fields.paymentMethod')}</span>
          <select
            onChange={(event) =>
              set('paymentMethod', event.target.value as TransactionFormValues['paymentMethod'])
            }
            value={values.paymentMethod}
          >
            <option value="">{t('transactions.form.noPaymentMethod')}</option>
            {PAYMENT_METHODS.map((method) => (
              <option key={method} value={method}>
                {t(`transactions.paymentMethods.${method}`)}
              </option>
            ))}
          </select>
        </label>

        <TransactionCategoryField
          key={categoryType}
          onChange={(categoryId) => set('categoryId', categoryId)}
          savedCategory={savedCategory(transaction)}
          type={categoryType}
          value={values.categoryId}
        />
      </div>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'transactions.form.saving' : 'transactions.form.save')}
        </button>
      </div>
    </form>
  );
}

function savedCategory(transaction: Transaction | undefined): { id: string; label: string } | null {
  const split = transaction?.splits[0];

  return split ? { id: split.categoryId, label: split.categoryLabel } : null;
}
