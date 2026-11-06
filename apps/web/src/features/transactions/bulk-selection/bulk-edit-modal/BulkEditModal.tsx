import type { BulkEditResult, PaymentMethod } from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { Modal } from '@/components/ui/modal/Modal';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type {
  BulkScope,
  BulkSelection,
} from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkEditResultView } from './BulkEditResult';
import { BulkLabelField } from './BulkLabelField';
import {
  EMPTY_DRAFT,
  MAX_COUNTERPARTY_LENGTH,
  MAX_NOTE_LENGTH,
  buildChanges,
  isDraftValid,
  type BulkEditDraft,
  type BulkEditField,
} from './bulkEditChanges';
import { BulkRequestError, useBulkEdit } from './useBulkEdit';
import styles from './BulkEditModal.module.css';

const PAYMENT_METHODS: readonly PaymentMethod[] = [
  'CARD',
  'TRANSFER',
  'DIRECT_DEBIT',
  'CHECK',
  'CASH',
  'OTHER',
];

interface BulkEditModalProps {
  /** Closes the dialog; `applied` tells the host the selection was written and is now stale. */
  close: (applied: boolean) => void;
  pageItems: BulkSelection['pageItems'];
  reloadAfterWrite: () => Promise<void>;
  scope: BulkScope;
}

/**
 * Edit of the label, payment method, note, counterparty and category of the selection. Only the
 * ticked rows are sent. The server applies what it can and reports the rest, so the outcome is
 * part of the same dialog rather than a toast.
 */
export function BulkEditModal({ close, pageItems, reloadAfterWrite, scope }: BulkEditModalProps) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState<BulkEditDraft>(EMPTY_DRAFT);
  // The server refuses a filter scope whose count moved; the user confirms the new one.
  const [expectedCount, setExpectedCount] = useState(
    scope.type === 'FILTER' ? scope.expectedCount : 0,
  );
  const [result, setResult] = useState<BulkEditResult | null>(null);
  const mutation = useBulkEdit({ reloadAfterWrite });
  const error = mutation.error instanceof BulkRequestError ? mutation.error : null;
  const effectiveScope: BulkScope = scope.type === 'FILTER' ? { ...scope, expectedCount } : scope;
  const count = scope.type === 'IDS' ? scope.ids.length : expectedCount;
  const valid = isDraftValid(draft, effectiveScope, pageItems);
  const done = () => close(result !== null);

  function patch(next: Partial<BulkEditDraft>) {
    setDraft((current) => ({ ...current, ...next }));
  }

  function tick(field: BulkEditField, on: boolean) {
    setDraft((current) => ({ ...current, checked: { ...current.checked, [field]: on } }));
  }

  function confirm() {
    mutation.mutate(
      { changes: buildChanges(draft, effectiveScope, pageItems), scope: effectiveScope },
      {
        onSuccess: setResult,
        onError: (failure) => {
          if (failure instanceof BulkRequestError && failure.actualCount !== null) {
            setExpectedCount(failure.actualCount);
          }
        },
      },
    );
  }

  /** A ticked row enables its control; an unticked one is shown but inert and never sent. */
  function row(field: BulkEditField, control: (disabled: boolean) => ReactNode, full = false) {
    const disabled = !draft.checked[field];
    return (
      <div className={styles.row} data-span={full || field === 'label' ? 'full' : undefined}>
        <label className={styles.tick}>
          <input
            checked={draft.checked[field]}
            onChange={(event) => tick(field, event.target.checked)}
            type="checkbox"
          />
          {t(`transactions.bulk.edit.${field}`)}
        </label>
        {control(disabled)}
      </div>
    );
  }

  return (
    <Modal
      intent={result ? 'detail' : 'edit'}
      close={done}
      title={t('transactions.bulk.title', { count })}
    >
      {result ? (
        <BulkEditResultView onDone={done} pageItems={pageItems} result={result} />
      ) : (
        <form
          className={styles.form}
          onSubmit={(event) => {
            event.preventDefault();
            if (valid && !mutation.isPending) confirm();
          }}
        >
          {scope.type === 'FILTER' ? <p>{t('transactions.bulk.filterNotice', { count })}</p> : null}
          <FormGrid>
            {row('label', (disabled) => (
              <fieldset className={styles.control} disabled={disabled}>
                <BulkLabelField
                  draft={draft}
                  items={pageItems}
                  onChange={patch}
                  scope={effectiveScope}
                />
              </fieldset>
            ))}
            {row('paymentMethod', (disabled) => (
              <label>
                {t('transactions.fields.paymentMethod')}
                <select
                  disabled={disabled}
                  onChange={(event) =>
                    patch({ paymentMethod: event.target.value as '' | PaymentMethod })
                  }
                  value={draft.paymentMethod}
                >
                  <option value="">{t('transactions.bulk.noPaymentMethod')}</option>
                  {PAYMENT_METHODS.map((method) => (
                    <option key={method} value={method}>
                      {t(`transactions.paymentMethods.${method}`)}
                    </option>
                  ))}
                </select>
              </label>
            ))}
            {row('counterparty', (disabled) => (
              <label>
                {t('transactions.fields.counterparty')}
                <input
                  disabled={disabled}
                  maxLength={MAX_COUNTERPARTY_LENGTH}
                  onChange={(event) => patch({ counterparty: event.target.value })}
                  type="text"
                  value={draft.counterparty}
                />
              </label>
            ))}
            {row('note', (disabled) => (
              <label>
                {t('transactions.fields.note')}
                <textarea
                  disabled={disabled}
                  maxLength={MAX_NOTE_LENGTH}
                  onChange={(event) => patch({ note: event.target.value })}
                  rows={3}
                  value={draft.note}
                />
              </label>
            ))}
            {row(
              'category',
              (disabled) => (
                <fieldset className={styles.control} disabled={disabled}>
                  <CategoryPicker
                    label={t('transactions.fields.category')}
                    onChange={(id) => patch({ categoryId: id })}
                    value={draft.categoryId}
                  />
                </fieldset>
              ),
              true,
            )}
          </FormGrid>
          <p className={styles.note}>{t('transactions.bulk.note')}</p>
          {error ? (
            <p className={styles.error} role="alert">
              {t(`transactions.bulk.errors.${error.kind}`, {
                count: error.actualCount ?? undefined,
              })}
            </p>
          ) : null}
          <ModalActions>
            <button className="secondary-action" onClick={done} type="button">
              {t('actions.cancel')}
            </button>
            <button
              className="primary-action"
              disabled={!valid || mutation.isPending}
              type="submit"
            >
              {t(mutation.isPending ? 'transactions.bulk.applying' : 'transactions.bulk.apply')}
            </button>
          </ModalActions>
        </form>
      )}
    </Modal>
  );
}
