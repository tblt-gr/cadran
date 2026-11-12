import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import type { Transfer, UpdateTransferRequest } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { TransferLegs } from './TransferLegs';
import styles from './TransferUpdateForm.module.css';

export function TransferUpdateForm({
  transfer,
  voiding,
  onCancel,
  pending,
  error,
  onSubmit,
}: {
  transfer: Transfer | null;
  voiding: boolean;
  onCancel: () => void;
  pending: boolean;
  error: string | null;
  onSubmit: (body: UpdateTransferRequest) => void;
}) {
  const { t } = useTranslation();
  // The optimistic version belongs to this draft, even when the read cache refreshes.
  const [draftTransfer] = useState(transfer);
  const [label, setLabel] = useState(transfer?.source.rawLabel ?? '');
  const [bookedOn, setBookedOn] = useState(transfer?.source.bookedOn ?? '');
  const [valueOn, setValueOn] = useState(transfer?.source.valueOn ?? '');
  const [feeValue, setFeeValue] = useState(transfer?.fee?.amount.value ?? '');
  const [note, setNote] = useState(transfer?.source.note ?? '');
  const [advancedOpen, setAdvancedOpen] = useState(
    () => (transfer?.fee?.amount.value ?? '') !== '' || (transfer?.source.note ?? '') !== '',
  );
  const [state, setState] = useState<'PENDING' | 'BOOKED'>(
    transfer?.source.state === 'PENDING' ? 'PENDING' : 'BOOKED',
  );
  return (
    <ModalForm
      className={styles.form}
      onSubmit={(event) => {
        event.preventDefault();
        if (draftTransfer)
          onSubmit({
            sourceAccountId: draftTransfer.source.accountId,
            targetAccountId: draftTransfer.target.accountId,
            label,
            bookedOn,
            valueOn: valueOn || null,
            note: note || null,
            state,
            fee:
              feeValue === ''
                ? null
                : { value: feeValue, assetCode: draftTransfer.source.amount.assetCode },
            version: draftTransfer.version,
          });
      }}
    >
      {error ? <p role="alert">{error}</p> : null}
      <fieldset disabled={transfer === null || pending || transfer.voidedAt !== null}>
        <FormGrid>
          <TransferLegs transfer={transfer} />
          <p className={styles.notice} data-span="full">
            {t(
              voiding
                ? 'transactions.workflow.voidTransferNotice'
                : 'transactions.workflow.transferImmutable',
            )}
          </p>
          {!voiding ? (
            <>
              <label>
                <span>{t('transactions.transfer.fields.label')}</span>
                <input
                  data-autofocus
                  required
                  maxLength={140}
                  value={label}
                  onChange={(event) => setLabel(event.target.value)}
                />
              </label>
              <label>
                <span>{t('transactions.fields.state')}</span>
                <select
                  value={state}
                  disabled={transfer?.source.state === 'BOOKED'}
                  onChange={(event) => setState(event.target.value as 'PENDING' | 'BOOKED')}
                >
                  <option value="PENDING">{t('transactions.states.PENDING')}</option>
                  <option value="BOOKED">{t('transactions.states.BOOKED')}</option>
                </select>
              </label>
              <label>
                <span>{t('transactions.fields.bookedOn')}</span>
                <input
                  required
                  type="date"
                  value={bookedOn}
                  onChange={(event) => setBookedOn(event.target.value)}
                />
              </label>
              <label>
                <span>{t('transactions.fields.valueOn')}</span>
                <input
                  type="date"
                  value={valueOn}
                  onChange={(event) => setValueOn(event.target.value)}
                />
              </label>
              <FormSection
                onToggle={setAdvancedOpen}
                open={advancedOpen}
                title={t('transactions.form.advanced')}
              >
                <label>
                  <span>{t('transactions.transfer.fields.fee')}</span>
                  <input
                    inputMode="decimal"
                    value={feeValue}
                    onChange={(event) => setFeeValue(event.target.value)}
                  />
                </label>
                <label data-span="full">
                  <span>{t('transactions.fields.note')}</span>
                  <textarea
                    maxLength={500}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                  />
                </label>
              </FormSection>
            </>
          ) : null}
        </FormGrid>
      </fieldset>
      <ModalActions>
        {voiding ? (
          <button
            className="secondary-action"
            disabled={transfer === null || pending || transfer.voidedAt !== null}
            onClick={onCancel}
            type="button"
          >
            {t('actions.cancel')}
          </button>
        ) : null}
        <button
          className="primary-action"
          disabled={transfer === null || pending || transfer.voidedAt !== null}
          type="submit"
        >
          {t(voiding ? 'transactions.workflow.confirmVoidTransfer' : 'transactions.form.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
