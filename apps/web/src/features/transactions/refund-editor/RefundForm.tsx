import type { Account, CreateRefundRequest, RefundableTransaction } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  compareDecimals,
  isCanonicalUnsignedDecimal,
  isZeroDecimal,
  sumDecimals,
} from '@/lib/decimal';
import { SplitEditor } from '@/features/transactions/split-editor/SplitEditor';
import type { SplitRowValues } from '@/features/transactions/split-editor/SplitRow';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import styles from './RefundForm.module.css';

interface RefundFormProps {
  accounts: Account[];
  onSubmit: (body: CreateRefundRequest) => void;
  pending: boolean;
  proposal: RefundableTransaction;
  submitError: TransactionErrorKind | null;
}

export function RefundForm({
  accounts,
  onSubmit,
  pending,
  proposal,
  submitError,
}: RefundFormProps) {
  const { t } = useTranslation();
  const eligibleAccounts = accounts.filter(
    (account) => account.assetCode === proposal.refundable.assetCode,
  );
  const [accountId, setAccountId] = useState(eligibleAccounts[0]?.id ?? '');
  const [amount, setAmount] = useState(proposal.refundable.value);
  const [bookedOn, setBookedOn] = useState('');
  const [rawLabel, setRawLabel] = useState('');
  const [counterparty, setCounterparty] = useState('');
  const [note, setNote] = useState('');
  const [editedSplits, setEditedSplits] = useState(false);
  const [showSplitErrors, setShowSplitErrors] = useState(false);
  const [splits, setSplits] = useState<SplitRowValues[]>(() =>
    proposal.proposedSplits.map((split, index) => ({
      amount: split.amount.value,
      analyticAxes: null,
      categoryId: split.categoryId,
      key: `proposal-${index}`,
      note: '',
    })),
  );
  const amountCanonical = isCanonicalUnsignedDecimal(amount);
  const amountInvalid =
    !amountCanonical ||
    (amountCanonical &&
      (isZeroDecimal(amount) || compareDecimals(amount, proposal.refundable.value) > 0));
  const splitAmountsValid = splits.every(
    (split) => isCanonicalUnsignedDecimal(split.amount) && !isZeroDecimal(split.amount),
  );
  const splitCategoriesValid =
    splits.every((split) => split.categoryId !== '') &&
    new Set(splits.map((split) => split.categoryId)).size === splits.length;
  const splitsValid =
    !editedSplits ||
    (splitAmountsValid &&
      splitCategoriesValid &&
      (proposal.proposedSplits.length === 0 || splits.length > 0) &&
      compareDecimals(sumDecimals(splits.map((split) => split.amount)), amount) === 0);
  const valid =
    accountId !== '' && bookedOn !== '' && rawLabel.trim() !== '' && !amountInvalid && splitsValid;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!valid) {
      setShowSplitErrors(true);
      return;
    }
    onSubmit({
      accountId,
      amount: { value: amount, assetCode: proposal.refundable.assetCode },
      bookedOn,
      rawLabel,
      counterparty: counterparty.trim() || null,
      note: note.trim() || null,
      splits: editedSplits
        ? splits.map((split) => ({
            categoryId: split.categoryId,
            amount: { value: split.amount, assetCode: proposal.refundable.assetCode },
            analyticAxes: split.analyticAxes,
            note: split.note.trim() || null,
          }))
        : null,
    });
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`transactions.errors.${submitError}`)}
        </p>
      ) : null}
      <p className={styles.remaining}>
        <span>{t('transactions.refund.remaining')}</span>
        <strong>
          {proposal.refundable.value} {proposal.refundable.assetCode}
        </strong>
      </p>
      <label>
        <span>{t('transactions.fields.account')}</span>
        <select onChange={(event) => setAccountId(event.target.value)} value={accountId}>
          {eligibleAccounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.label}
            </option>
          ))}
        </select>
      </label>
      <label>
        <span>{t('transactions.fields.amount')}</span>
        <input
          aria-invalid={amountInvalid || undefined}
          autoComplete="off"
          inputMode="decimal"
          onChange={(event) => setAmount(event.target.value)}
          value={amount}
        />
        {amountInvalid ? <small>{t('transactions.refund.amountTooHigh')}</small> : null}
      </label>
      <label>
        <span>{t('transactions.fields.bookedOn')}</span>
        <input
          onChange={(event) => setBookedOn(event.target.value)}
          required
          type="date"
          value={bookedOn}
        />
      </label>
      <label>
        <span>{t('transactions.fields.label')}</span>
        <input onChange={(event) => setRawLabel(event.target.value)} required value={rawLabel} />
      </label>
      <label>
        <span>{t('transactions.fields.counterparty')}</span>
        <input onChange={(event) => setCounterparty(event.target.value)} value={counterparty} />
      </label>
      <label>
        <span>{t('transactions.fields.note')}</span>
        <input onChange={(event) => setNote(event.target.value)} value={note} />
      </label>
      <div>
        <p>{t('transactions.refund.allocation')}</p>
        <SplitEditor
          assetCode={proposal.refundable.assetCode}
          onChange={(rows) => {
            setEditedSplits(true);
            setSplits(rows);
          }}
          preferredType="EXPENSE"
          rows={splits}
          showErrors={showSplitErrors}
          total={amount}
        />
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled={!valid || pending} type="submit">
          {pending ? t('transactions.refund.saving') : t('transactions.refund.save')}
        </button>
      </div>
    </form>
  );
}
