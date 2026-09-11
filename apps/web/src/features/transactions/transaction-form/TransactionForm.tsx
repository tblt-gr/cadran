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
  ADVANCED_FIELDS,
  AdvancedTransactionFields,
} from './advanced-fields/AdvancedTransactionFields';
import { CategorizationField } from './categorization-field/CategorizationField';
import {
  categoryTypeForAmount,
  initialTransactionValues,
  transactionRequest,
  validateTransactionValues,
  type TransactionFormValues,
} from './transactionFormValues';
import type { SavedCategory } from './transaction-category-field/TransactionCategoryField';
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

/**
 * The fields of nearly every entry — account, date, amount, nature, label, payment
 * method and category — come first; the rest is folded under "advanced fields".
 */
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
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const resolved =
    values.accountId === '' && accounts[0] !== undefined
      ? { ...values, accountId: accounts[0].id }
      : values;
  const errors = validateTransactionValues(resolved, accounts, transaction);
  const editing = transaction !== undefined;
  const stateLocked = editing && transaction.state !== 'PENDING';
  const rawLabelLocked = editing && transaction.source !== 'MANUAL';
  const preferredType = categoryTypeForAmount(values.amountValue, values.nature);

  function set<K extends keyof TransactionFormValues>(field: K, value: TransactionFormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      // An invalid field must never stay folded out of sight.
      if (ADVANCED_FIELDS.some((field) => errors[field])) {
        setAdvancedOpen(true);
      }
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
          <span>{t('transactions.fields.amount')}</span>
          <input
            aria-invalid={showErrors && errors.amountValue ? true : undefined}
            autoComplete="off"
            data-autofocus
            inputMode="decimal"
            onChange={(event) => set('amountValue', event.target.value)}
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
            onChange={(event) =>
              set('nature', event.target.value as TransactionFormValues['nature'])
            }
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

        <CategorizationField
          assetCode={
            transaction?.amount.assetCode ??
            accounts.find((account) => account.id === resolved.accountId)?.assetCode ??
            ''
          }
          categoryError={
            errors.categoryId && values.categoryType !== null
              ? t(`transactions.validation.categoryMismatch.${values.categoryType}`)
              : null
          }
          categoryId={values.categoryId}
          onChangeCategory={(categoryId, categoryType) =>
            setValues((current) => ({ ...current, categoryId, categoryType }))
          }
          onChangeSplitMode={(splitMode) => set('splitMode', splitMode)}
          onChangeSplits={(splits) => set('splits', splits)}
          preferredType={preferredType}
          savedCategory={savedCategory(transaction)}
          showErrors={showErrors}
          splitMode={values.splitMode}
          splits={values.splits}
          total={values.amountValue}
        />

        <AdvancedTransactionFields
          errors={errors}
          onChange={set}
          onToggle={setAdvancedOpen}
          open={advancedOpen}
          rejectable={editing && transaction.state === 'PENDING'}
          showErrors={showErrors}
          stateLockedHint={stateLocked ? t('transactions.form.stateLocked') : null}
          values={values}
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

function savedCategory(transaction: Transaction | undefined): SavedCategory | null {
  const split = transaction?.splits[0];

  return split
    ? {
        color: split.categoryColor,
        icon: split.categoryIcon,
        id: split.categoryId,
        label: split.categoryLabel,
      }
    : null;
}
