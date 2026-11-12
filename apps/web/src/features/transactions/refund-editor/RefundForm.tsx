import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { Account, CreateRefundRequest, RefundableTransaction } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  compareDecimals,
  formatAmount,
  isCanonicalUnsignedDecimal,
  isZeroDecimal,
  sumDecimals,
} from '@/lib/decimal';
import { SplitEditor } from '@/features/transactions/split-editor/SplitEditor';
import type { SplitRowValues } from '@/features/transactions/split-editor/SplitRow';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import styles from './RefundForm.module.css';

interface RefundFormProps {
  accounts: Account[];
  onSubmit: (body: CreateRefundRequest) => void;
  pending: boolean;
  proposal: RefundableTransaction;
  submitError: TransactionErrorKind | null;
  submitErrorDetail: string | null;
}

export function RefundForm({
  accounts,
  onSubmit,
  pending,
  proposal,
  submitError,
  submitErrorDetail,
}: RefundFormProps) {
  const { i18n, t } = useTranslation();
  const eligibleAccounts = accounts.filter(
    (account) => account.assetCode === proposal.refundable.assetCode,
  );
  const [accountId, setAccountId] = useState(
    eligibleAccounts.find((account) => account.isPrimary && account.status === 'ACTIVE')?.id ??
      eligibleAccounts[0]?.id ??
      '',
  );
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
  const normalizedAmount = amount.replace(',', '.');
  const amountCanonical = isCanonicalUnsignedDecimal(normalizedAmount);
  const amountTooHigh =
    amountCanonical &&
    !isZeroDecimal(normalizedAmount) &&
    compareDecimals(normalizedAmount, proposal.refundable.value) > 0;
  const amountInvalid = !amountCanonical || isZeroDecimal(normalizedAmount) || amountTooHigh;
  const splitAmountsValid = splits.every(
    (split) => isCanonicalUnsignedDecimal(split.amount) && !isZeroDecimal(split.amount),
  );
  const splitCategoriesValid =
    splits.every((split) => split.categoryId !== '') &&
    new Set(splits.map((split) => split.categoryId)).size === splits.length &&
    splits.every(
      (split) =>
        split.categoryType === undefined ||
        split.categoryType === null ||
        split.categoryType === 'EXPENSE',
    );
  // The displayed, unedited proposal was computed by the server for the full
  // refundable amount: it stops summing to a smaller, hand-typed amount, and
  // recomputing the allocation here would duplicate server-side rounding
  // logic. So an unedited proposal is only valid while it still matches.
  const proposalMatchesAmount =
    amountCanonical && compareDecimals(normalizedAmount, proposal.refundable.value) === 0;
  const splitsValid = editedSplits
    ? splitAmountsValid &&
      splitCategoriesValid &&
      (proposal.proposedSplits.length === 0 || splits.length > 0) &&
      amountCanonical &&
      compareDecimals(sumDecimals(splits.map((split) => split.amount)), normalizedAmount) === 0
    : proposal.proposedSplits.length === 0 || proposalMatchesAmount;
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
      amount: { value: normalizedAmount, assetCode: proposal.refundable.assetCode },
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

  const [advancedOpen, setAdvancedOpen] = useState(false);

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <TransactionSubmitError
          accountId={accountId}
          className={styles.alert}
          detail={submitErrorDetail}
          kind={submitError}
        />
      ) : null}
      <p className={styles.remaining}>
        <span>{t('transactions.refund.remaining')}</span>
        <strong>
          {formatAmount(proposal.refundable.value, proposal.refundable.assetCode, i18n.language)}
        </strong>
      </p>
      <FormGrid>
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
            data-autofocus
            aria-label={t('transactions.fields.amount')}
            aria-describedby={amountInvalid ? 'refund-amount-error' : undefined}
            aria-invalid={amountInvalid || undefined}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => setAmount(event.target.value)}
            value={amount}
          />
          {amountInvalid ? (
            <small id="refund-amount-error" role={amountTooHigh ? 'alert' : undefined}>
              {t(
                amountTooHigh
                  ? 'transactions.refund.amountTooHigh'
                  : 'transactions.refund.amountInvalid',
              )}
            </small>
          ) : null}
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
        <FormSection
          title={t('transactions.form.advanced')}
          open={advancedOpen}
          onToggle={setAdvancedOpen}
        >
          <label>
            <span>{t('transactions.fields.counterparty')}</span>
            <input onChange={(event) => setCounterparty(event.target.value)} value={counterparty} />
          </label>
          <label>
            <span>{t('transactions.fields.note')}</span>
            <input onChange={(event) => setNote(event.target.value)} value={note} />
          </label>
        </FormSection>
        <div data-span="full">
          <p>{t('transactions.refund.allocation')}</p>
          {!editedSplits && !proposalMatchesAmount && proposal.proposedSplits.length > 0 ? (
            <p role="alert">{t('transactions.refund.allocationOutOfDate')}</p>
          ) : null}
          <SplitEditor
            assetCode={proposal.refundable.assetCode}
            expectedCategoryType="EXPENSE"
            onChange={(rows) => {
              setEditedSplits(true);
              setSplits(rows);
            }}
            preferredType="EXPENSE"
            rows={splits}
            showErrors={showSplitErrors}
            total={normalizedAmount}
          />
        </div>
      </FormGrid>
      {!valid && !pending ? (
        <p className={styles.hint}>
          {t(
            accountId === '' || bookedOn === '' || rawLabel.trim() === '' || amountInvalid
              ? 'transactions.refund.requiredFields'
              : 'transactions.refund.allocationInvalid',
          )}
        </p>
      ) : null}
      <ModalActions>
        <button className="primary-action" disabled={!valid || pending} type="submit">
          {pending ? t('transactions.refund.saving') : t('transactions.refund.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
