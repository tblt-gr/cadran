import type { Account, AccountValuation, RecordAccountBalanceRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { isCanonicalDecimal } from '@/lib/decimal';
import styles from './RecordBalanceForm.module.css';

const MAX_COMMENT = 200;

interface RecordBalanceFormProps {
  account: Account;
  valuation: AccountValuation;
  onCancel: () => void;
  onSubmit: (body: RecordAccountBalanceRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Records a manual observed balance in the account's own unit.
 *
 * The figure is sent as typed. Replacing the active snapshot of that day
 * presents the version the list already showed, so a concurrent write is a
 * conflict rather than a silent overwrite.
 */
export function RecordBalanceForm({
  account,
  valuation,
  onCancel: _onCancel,
  onSubmit,
  pending,
  submitError,
}: RecordBalanceFormProps) {
  const { t } = useTranslation();
  const [asOf, setAsOf] = useState(valuation.requestedOn);
  const [amount, setAmount] = useState(valuation.amount?.value ?? '');
  const [comment, setComment] = useState('');
  const [showErrors, setShowErrors] = useState(false);

  const trimmedAmount = amount.trim();
  const trimmedComment = comment.trim();
  const amountInvalid = !isCanonicalDecimal(trimmedAmount);
  const commentInvalid = trimmedComment.length > MAX_COMMENT;
  const latestAllowed =
    account.closedOn !== null && account.closedOn < valuation.requestedOn
      ? account.closedOn
      : valuation.requestedOn;
  const asOfInvalid = asOf === '' || asOf < account.openedOn || asOf > latestAllowed;

  function submit(event: FormEvent) {
    event.preventDefault();
    if (amountInvalid || commentInvalid || asOfInvalid) {
      setShowErrors(true);

      return;
    }

    const replacingSameDay =
      valuation.quality !== 'MISSING' &&
      valuation.source === 'MANUAL' &&
      valuation.asOf === asOf &&
      valuation.version !== null;

    onSubmit({
      asOf,
      amount: trimmedAmount,
      amountAssetCode: account.assetCode,
      comment: trimmedComment === '' ? null : trimmedComment,
      version: replacingSameDay ? valuation.version : null,
    });
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.balances.errors.${submitError}`)}
        </p>
      ) : null}

      <p className={styles.hint}>{t('accounts.balances.formHint', { asset: account.assetCode })}</p>

      <div className={styles.fields}>
        <FormField
          error={showErrors && asOfInvalid ? t('accounts.balances.validation.asOf') : undefined}
          hint={t('accounts.balances.asOfHint')}
          label={t('accounts.balances.fields.asOf')}
          name="balance-as-of"
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={showErrors && asOfInvalid ? true : undefined}
              id={fieldId}
              max={latestAllowed}
              min={account.openedOn}
              onChange={(event) => setAsOf(event.target.value)}
              required
              type="date"
              value={asOf}
            />
          )}
        </FormField>

        <FormField
          error={showErrors && amountInvalid ? t('accounts.balances.validation.amount') : undefined}
          hint={t('accounts.balances.amountHint')}
          label={t('accounts.balances.fields.amount')}
          name="balance-amount"
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={showErrors && amountInvalid ? true : undefined}
              autoComplete="off"
              id={fieldId}
              inputMode="decimal"
              onChange={(event) => setAmount(event.target.value)}
              required
              spellCheck={false}
              value={amount}
            />
          )}
        </FormField>
      </div>

      <FormField
        error={showErrors && commentInvalid ? t('accounts.balances.validation.comment') : undefined}
        hint={t('accounts.balances.commentHint')}
        label={t('accounts.balances.fields.comment')}
        name="balance-comment"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={showErrors && commentInvalid ? true : undefined}
            id={fieldId}
            maxLength={MAX_COMMENT}
            onChange={(event) => setComment(event.target.value)}
            value={comment}
          />
        )}
      </FormField>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'accounts.balances.recording' : 'accounts.balances.record')}
        </button>
      </div>
    </form>
  );
}
