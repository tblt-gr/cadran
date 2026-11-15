import type { Account, CreateTransactionRequest } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import {
  hasRecordedAmount,
  SignedAmountHint,
} from '@/components/ui/signed-amount-hint/SignedAmountHint';
import { formatAmount } from '@/lib/decimal';
import type { AmountDirection } from '@/lib/signedAmount';
import type {
  TransactionFormErrors,
  TransactionFormValues,
} from '@/features/transactions/transaction-form/transactionFormValues';
import styles from './TransactionMainFields.module.css';

const NATURES: TransactionFormValues['nature'][] = ['EXPENSE', 'INCOME', 'FEE', 'ADJUSTMENT'];
const PAYMENT_METHODS: Array<NonNullable<CreateTransactionRequest['paymentMethod']>> = [
  'CARD',
  'TRANSFER',
  'DIRECT_DEBIT',
  'CHECK',
  'CASH',
  'OTHER',
];

interface TransactionMainFieldsProps {
  accounts: Account[];
  amountAssetCode: string;
  /** The exact balance after this entry, or `null` when it cannot be calculated. */
  balanceAfter: { value: string; assetCode: string } | null;
  direction: AmountDirection | null;
  editing: boolean;
  errors: TransactionFormErrors;
  onAccountChosen: () => void;
  onChange: <K extends keyof TransactionFormValues>(
    field: K,
    value: TransactionFormValues[K],
  ) => void;
  rawLabelLocked: boolean;
  showErrors: boolean;
  /** The date is later this month, so the row can only be pending. */
  upcoming: boolean;
  /** The values with the default account and the planned state applied. */
  resolvedAccountId: string;
  values: TransactionFormValues;
}

/** The account, date, label, amount, nature and payment method fields, folded in the form grid. */
export function TransactionMainFields({
  accounts,
  amountAssetCode,
  balanceAfter,
  direction,
  editing,
  errors,
  onAccountChosen,
  onChange: set,
  rawLabelLocked,
  resolvedAccountId,
  showErrors,
  upcoming,
  values,
}: TransactionMainFieldsProps) {
  const { t, i18n } = useTranslation();
  const errorId = useId();

  // The exact balance the server reports for an account, when it can be calculated.
  const accountOptionLabel = (account: Account) => {
    const balance = account.valuation?.amount ?? null;

    return balance === null
      ? account.label
      : `${account.label} · ${formatAmount(balance.value, balance.assetCode, i18n.language)}`;
  };

  return (
    <>
      <label>
        <span>{t('transactions.fields.account')}</span>
        <select
          aria-describedby={
            [
              editing ? 'transaction-account-hint' : undefined,
              showErrors && errors.accountId ? `${errorId}-accountId` : undefined,
            ]
              .filter(Boolean)
              .join(' ') || undefined
          }
          aria-invalid={showErrors && errors.accountId ? true : undefined}
          aria-label={t('transactions.fields.account')}
          disabled={editing}
          onChange={(event) => {
            onAccountChosen();
            set('accountId', event.target.value);
          }}
          value={resolvedAccountId}
        >
          {!editing ? (
            <option value="">{t('transactions.transfer.fields.selectAccount')}</option>
          ) : null}
          {accounts.map((account) => (
            <option key={account.id} value={account.id}>
              {accountOptionLabel(account)}
            </option>
          ))}
        </select>
        {showErrors && errors.accountId ? (
          <small id={`${errorId}-accountId`}>{t('transactions.validation.account')}</small>
        ) : null}
        {balanceAfter ? (
          <small className={styles.hint}>
            {t('transactions.transfer.balanceAfter', {
              amount: formatAmount(balanceAfter.value, balanceAfter.assetCode, i18n.language),
            })}
          </small>
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
          aria-describedby={
            [
              upcoming ? 'transaction-upcoming-hint' : undefined,
              showErrors && errors.bookedOn ? `${errorId}-bookedOn` : undefined,
            ]
              .filter(Boolean)
              .join(' ') || undefined
          }
          aria-invalid={showErrors && errors.bookedOn ? true : undefined}
          onChange={(event) => set('bookedOn', event.target.value)}
          type="date"
          value={values.bookedOn}
        />
        {upcoming ? (
          <small className={styles.hint} id="transaction-upcoming-hint">
            {t('transactions.form.upcomingHint')}
          </small>
        ) : null}
        {showErrors && errors.bookedOn ? (
          <small id={`${errorId}-bookedOn`}>{t('transactions.validation.bookedOn')}</small>
        ) : null}
      </label>

      {rawLabelLocked ? (
        <>
          <label>
            <span>{t('transactions.fields.displayLabel')}</span>
            <input
              aria-describedby={showErrors && errors.userLabel ? `${errorId}-userLabel` : undefined}
              aria-invalid={showErrors && errors.userLabel ? true : undefined}
              aria-label={t('transactions.fields.displayLabel')}
              data-autofocus
              onChange={(event) => set('userLabel', event.target.value)}
              value={values.userLabel}
            />
            {showErrors && errors.userLabel ? (
              <small id={`${errorId}-userLabel`}>{t('transactions.validation.userLabel')}</small>
            ) : null}
          </label>
          {rawLabelLocked ? (
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
          ) : null}
        </>
      ) : (
        <label>
          <span>{t('transactions.fields.label')}</span>
          <input
            aria-describedby={showErrors && errors.rawLabel ? `${errorId}-rawLabel` : undefined}
            aria-invalid={showErrors && errors.rawLabel ? true : undefined}
            aria-label={t('transactions.fields.label')}
            data-autofocus
            onChange={(event) => set('rawLabel', event.target.value)}
            value={values.rawLabel}
          />
          {showErrors && errors.rawLabel ? (
            <small id={`${errorId}-rawLabel`}>{t('transactions.validation.rawLabel')}</small>
          ) : null}
        </label>
      )}

      <label>
        <span>{t('transactions.fields.amount')}</span>
        <input
          aria-describedby={[
            hasRecordedAmount(values.amountValue) ? `${errorId}-amountHint` : null,
            showErrors && errors.amountValue ? `${errorId}-amountValue` : null,
          ]
            .filter(Boolean)
            .join(' ')}
          aria-invalid={showErrors && errors.amountValue ? true : undefined}
          aria-label={t('transactions.fields.amount')}
          autoComplete="off"
          inputMode="decimal"
          onChange={(event) => set('amountValue', event.target.value)}
          spellCheck={false}
          value={values.amountValue}
        />
        {showErrors && errors.amountValue ? (
          <small id={`${errorId}-amountValue`}>{t('transactions.validation.amount')}</small>
        ) : null}
        <SignedAmountHint
          assetCode={amountAssetCode}
          direction={direction}
          id={`${errorId}-amountHint`}
          recordedOnly
          typed={values.amountValue}
        />
      </label>

      <label>
        <span>{t('transactions.fields.nature')}</span>
        <select
          aria-describedby={showErrors && errors.nature ? `${errorId}-nature` : undefined}
          aria-invalid={showErrors && errors.nature ? true : undefined}
          onChange={(event) => set('nature', event.target.value as TransactionFormValues['nature'])}
          value={values.nature}
        >
          {NATURES.map((nature) => (
            <option key={nature} value={nature}>
              {t(`transactions.natures.${nature}`)}
            </option>
          ))}
        </select>
        {showErrors && errors.nature ? (
          <small id={`${errorId}-nature`}>{t('transactions.validation.nature')}</small>
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
    </>
  );
}
