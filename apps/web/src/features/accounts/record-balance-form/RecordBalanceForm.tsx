import type {
  Account,
  AccountBalanceSnapshot,
  AccountValuation,
  RecordAccountBalanceRequest,
} from '@cadran/api-client';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import { accountErrorKind, type AccountErrorKind } from '@/features/accounts/accountError';
import { formatCalendarNumericDay, isCanonicalDecimal } from '@/lib/decimal';
import { BalanceSnapshotsList } from './balance-snapshots/BalanceSnapshotsList';
import { useCorrectBalance } from './balance-snapshots/useBalanceSnapshots';
import styles from './RecordBalanceForm.module.css';

const MAX_COMMENT = 200;

interface RecordBalanceFormProps {
  account: Account;
  valuation: AccountValuation;
  onCancel: () => void;
  /** Called once a listed snapshot has been amended in place. */
  onCorrected: () => void;
  onSubmit: (body: RecordAccountBalanceRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Records a manual observed balance in the account's own unit.
 *
 * The figure is sent as typed. Replacing the active snapshot of that day
 * presents the version the list already showed, so a concurrent write is a
 * conflict rather than a silent overwrite. Correcting a listed snapshot amends
 * that very row (no second snapshot, nothing withdrawn); its date is then fixed,
 * since another date would be a different snapshot.
 */
export function RecordBalanceForm({
  account,
  valuation,
  onCancel: _onCancel,
  onCorrected,
  onSubmit,
  pending,
  submitError,
}: RecordBalanceFormProps) {
  const { t } = useTranslation();
  const [asOf, setAsOf] = useState(valuation.requestedOn);
  const [amount, setAmount] = useState(valuation.amount?.value ?? '');
  const [comment, setComment] = useState('');
  const [showErrors, setShowErrors] = useState(false);
  const [snapshotsOpen, setSnapshotsOpen] = useState(false);
  const [editing, setEditing] = useState<AccountBalanceSnapshot | null>(null);
  const correct = useCorrectBalance(account.id);
  const editingRef = useRef<HTMLDivElement>(null);
  const amountRef = useRef<HTMLInputElement>(null);

  const correctError = accountErrorKind(correct.error, correct.isError);
  const shownError = editing ? correctError : submitError;
  const busy = pending || correct.isPending;
  // The list sits below the form: bring the form back into view so the change of mode is seen.
  useEffect(() => {
    if (editing === null) {
      return;
    }
    editingRef.current?.scrollIntoView({ block: 'start' });
    amountRef.current?.focus({ preventScroll: true });
  }, [editing]);

  const trimmedAmount = amount.trim();
  const trimmedComment = comment.trim();
  const amountInvalid = !isCanonicalDecimal(trimmedAmount);
  const commentInvalid = trimmedComment.length > MAX_COMMENT;
  const latestAllowed =
    account.closedOn !== null && account.closedOn < valuation.requestedOn
      ? account.closedOn
      : valuation.requestedOn;
  const asOfInvalid =
    editing === null && (asOf === '' || asOf < account.openedOn || asOf > latestAllowed);

  function startEditing(snapshot: AccountBalanceSnapshot) {
    setEditing(snapshot);
    setAsOf(snapshot.asOf);
    setAmount(snapshot.amount.value);
    setComment(snapshot.comment ?? '');
    setShowErrors(false);
    correct.reset();
  }

  function stopEditing() {
    correct.reset();
    setEditing(null);
    setAsOf(valuation.requestedOn);
    setAmount(valuation.amount?.value ?? '');
    setComment('');
    setShowErrors(false);
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    if (amountInvalid || commentInvalid || asOfInvalid) {
      setShowErrors(true);

      return;
    }

    if (editing) {
      correct.mutate(
        {
          snapshot: editing,
          body: {
            amount: trimmedAmount,
            amountAssetCode: account.assetCode,
            comment: trimmedComment === '' ? null : trimmedComment,
            version: editing.version,
          },
        },
        { onSuccess: onCorrected },
      );

      return;
    }

    // A derived balance reports today's date, but its version belongs to the
    // anchor snapshot. Sending that version for a new day is a false conflict.
    const snapshotDay = valuation.derivation?.anchorAsOf ?? valuation.asOf;
    const replacingSameDay =
      valuation.quality !== 'MISSING' &&
      valuation.source === 'MANUAL' &&
      snapshotDay === asOf &&
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
    <div className={styles.wrapper}>
      <form className={styles.form} noValidate onSubmit={submit}>
        {shownError ? (
          <p className={styles.alert} role="alert">
            {t(`accounts.balances.errors.${shownError}`)}
          </p>
        ) : null}

        {editing ? (
          <div className={styles.editing} ref={editingRef} role="status">
            <p className={styles.hint}>
              {t('accounts.balances.editing', { date: formatCalendarNumericDay(editing.asOf) })}
            </p>
            <button className="secondary-action" onClick={stopEditing} type="button">
              {t('accounts.balances.cancelEditing')}
            </button>
          </div>
        ) : (
          <p className={styles.hint}>
            {t('accounts.balances.formHint', { asset: account.assetCode })}
          </p>
        )}

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
                readOnly={editing !== null}
                required
                type="date"
                value={asOf}
              />
            )}
          </FormField>

          <FormField
            error={
              showErrors && amountInvalid ? t('accounts.balances.validation.amount') : undefined
            }
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
                ref={amountRef}
                required
                spellCheck={false}
                value={amount}
              />
            )}
          </FormField>
        </div>

        <FormField
          error={
            showErrors && commentInvalid ? t('accounts.balances.validation.comment') : undefined
          }
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
          <button className="primary-action" disabled={busy} type="submit">
            {t(
              busy
                ? 'accounts.balances.recording'
                : editing
                  ? 'accounts.balances.saveEdit'
                  : 'accounts.balances.record',
            )}
          </button>
        </div>
      </form>
      <Disclosure
        onToggle={setSnapshotsOpen}
        open={snapshotsOpen}
        title={t('accounts.snapshots.title')}
      >
        {snapshotsOpen ? (
          <BalanceSnapshotsList accountId={account.id} onEdit={startEditing} />
        ) : null}
      </Disclosure>
    </div>
  );
}
