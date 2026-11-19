import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type {
  Account,
  AccountBalanceSnapshot,
  AccountValuation,
  RecordAccountBalanceRequest,
} from '@cadran/api-client';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import {
  accountErrorDay,
  accountErrorKind,
  type AccountErrorKind,
} from '@/features/accounts/accountError';
import { formatCalendarNumericDay, isCanonicalDecimal } from '@/lib/decimal';
import { BalanceFields } from './balance-fields/BalanceFields';
import { BalanceCommentField } from './balance-comment-field/BalanceCommentField';
import { BalanceSnapshotsList } from './balance-snapshots/BalanceSnapshotsList';
import { latestBalanceDay, newSnapshotRequest } from './newSnapshotRequest';
import { useCorrectBalance } from './balance-snapshots/useBalanceSnapshots';
import styles from './RecordBalanceForm.module.css';

const MAX_COMMENT = 200;

interface RecordBalanceFormProps {
  account: Account;
  valuation: AccountValuation;
  /** Opens already in correction mode on this snapshot, for a correction started from a list. */
  initialEditing?: AccountBalanceSnapshot;
  /** Opens the date field on this day instead of the account's requested day. */
  initialAsOf?: string;
  onCancel: () => void;
  /** Called once a listed snapshot has been amended in place. */
  onCorrected: () => void;
  onSubmit: (body: RecordAccountBalanceRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
  /** For an insufficient balance: the first day the account would be negative (ISO 8601). */
  submitErrorDay?: string | null;
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
  initialEditing,
  initialAsOf,
  onCancel: _onCancel,
  onCorrected,
  onSubmit,
  pending,
  submitError,
  submitErrorDay,
}: RecordBalanceFormProps) {
  const { t } = useTranslation();
  const [asOf, setAsOf] = useState(initialEditing?.asOf ?? initialAsOf ?? valuation.requestedOn);
  const [amount, setAmount] = useState(
    initialEditing?.amount.value ?? valuation.amount?.value ?? '',
  );
  const [comment, setComment] = useState(initialEditing?.comment ?? '');
  const [advancedOpen, setAdvancedOpen] = useState(Boolean(initialEditing?.comment));
  const [showErrors, setShowErrors] = useState(false);
  const [snapshotsOpen, setSnapshotsOpen] = useState(false);
  const [editing, setEditing] = useState<AccountBalanceSnapshot | null>(initialEditing ?? null);
  const correct = useCorrectBalance(account.id);
  const editingRef = useRef<HTMLDivElement>(null);
  const amountRef = useRef<HTMLInputElement>(null);

  const correctError = accountErrorKind(correct.error, correct.isError);
  const shownError = editing ? correctError : submitError;
  // A refused balance is shown on the amount input, not in the banner.
  const refused = shownError === 'insufficientBalance';
  const refusalDay = editing ? accountErrorDay(correct.error) : (submitErrorDay ?? null);
  const busy = pending || correct.isPending;
  // The list sits below the form: bring the form back into view so the change of mode is seen.
  useEffect(() => {
    if (editing === null) {
      return;
    }
    editingRef.current?.scrollIntoView({ block: 'start' });
    amountRef.current?.focus({ preventScroll: true });
  }, [editing]);

  useEffect(() => {
    if (refused) amountRef.current?.focus();
  }, [refused]);

  const trimmedAmount = amount.trim();
  const trimmedComment = comment.trim();
  const amountInvalid = !isCanonicalDecimal(trimmedAmount);
  const commentInvalid = trimmedComment.length > MAX_COMMENT;
  const latestAllowed = latestBalanceDay(account, valuation);
  const asOfInvalid =
    editing === null && (asOf === '' || asOf < account.openedOn || asOf > latestAllowed);

  function startEditing(snapshot: AccountBalanceSnapshot) {
    setEditing(snapshot);
    setAsOf(snapshot.asOf);
    setAmount(snapshot.amount.value);
    setComment(snapshot.comment ?? '');
    setAdvancedOpen(Boolean(snapshot.comment));
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

    onSubmit(newSnapshotRequest(account, valuation, asOf, trimmedAmount, trimmedComment));
  }

  return (
    <div className={styles.wrapper}>
      <ModalForm className={styles.form} noValidate onSubmit={submit}>
        {shownError && !refused ? (
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

        <BalanceFields
          amount={amount}
          amountInvalid={amountInvalid}
          amountRef={amountRef}
          asOf={asOf}
          asOfInvalid={asOfInvalid}
          className={styles.fields}
          dateLocked={editing !== null}
          latestAllowed={latestAllowed}
          minDay={account.openedOn}
          onAmount={setAmount}
          onAsOf={setAsOf}
          refusal={refused ? { day: refusalDay } : null}
          showErrors={showErrors}
        />

        <FormSection
          title={t('transactions.form.advanced')}
          open={advancedOpen || (showErrors && commentInvalid)}
          onToggle={setAdvancedOpen}
        >
          <BalanceCommentField
            invalid={showErrors && commentInvalid}
            maxLength={MAX_COMMENT}
            onChange={setComment}
            value={comment}
          />
        </FormSection>

        <ModalActions>
          <button className="primary-action" disabled={busy} type="submit">
            {t(
              busy
                ? 'accounts.balances.recording'
                : editing
                  ? 'accounts.balances.saveEdit'
                  : 'accounts.balances.record',
            )}
          </button>
        </ModalActions>
      </ModalForm>
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
