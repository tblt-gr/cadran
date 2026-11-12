import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import type { BulkEditResult, PaymentMethod } from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { Modal } from '@/components/ui/modal/Modal';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type {
  BulkScope,
  BulkSelection,
} from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkEditRow } from './BulkEditRow';
import { BulkMoveRows } from './bulk-move-rows/BulkMoveRows';
import { BulkMovePreview } from './bulk-move-preview/BulkMovePreview';
import { BulkEditResultView } from './BulkEditResult';
import { BulkLabelField } from './BulkLabelField';
import {
  EMPTY_DRAFT,
  MAX_COUNTERPARTY_LENGTH,
  MAX_NOTE_LENGTH,
  isDraftValid,
  isMove,
  selectionAssets,
  type BulkEditDraft,
  type BulkEditField,
} from './bulkEditChanges';
import { useBulkMoveFlow } from './useBulkMoveFlow';
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
 * Edit of the label, payment method, note, counterparty, category, account and booked day of the
 * selection. Only the ticked rows are sent. The server applies what it can and reports the rest,
 * so the outcome is part of the same dialog rather than a toast. A move first shows its impact on
 * the balances and is applied only against that preview.
 */
export function BulkEditModal({ close, pageItems, reloadAfterWrite, scope }: BulkEditModalProps) {
  const { t } = useTranslation();
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const [draft, setDraft] = useState<BulkEditDraft>({ ...EMPTY_DRAFT, bookedOn: today });
  // The server refuses a filter scope whose count moved; the user confirms the new one.
  const [expectedCount, setExpectedCount] = useState(
    scope.type === 'FILTER' ? scope.expectedCount : 0,
  );
  const [result, setResult] = useState<BulkEditResult | null>(null);
  const effectiveScope: BulkScope = scope.type === 'FILTER' ? { ...scope, expectedCount } : scope;
  const count = scope.type === 'IDS' ? scope.ids.length : expectedCount;
  const flow = useBulkMoveFlow({
    draft,
    onActualCount: setExpectedCount,
    onApplied: setResult,
    pageItems,
    reloadAfterWrite,
    scope: effectiveScope,
  });
  const { error, stage } = flow;
  const valid = isDraftValid(draft, effectiveScope, pageItems);
  const done = () => close(result !== null);

  function patch(next: Partial<BulkEditDraft>) {
    setDraft((current) => ({ ...current, ...next }));
  }

  function tick(field: BulkEditField, on: boolean) {
    setDraft((current) => ({ ...current, checked: { ...current.checked, [field]: on } }));
  }

  function row(field: BulkEditField, control: (disabled: boolean) => ReactNode, full = false) {
    return (
      <BulkEditRow
        checked={draft.checked[field]}
        field={field}
        full={full}
        onTick={(on) => tick(field, on)}
      >
        {control}
      </BulkEditRow>
    );
  }

  return (
    <Modal
      intent={result ? 'detail' : stage === 'preview' ? 'confirm' : 'edit'}
      close={done}
      title={t('transactions.bulk.title', { count })}
    >
      {result ? (
        <BulkEditResultView onDone={done} pageItems={pageItems} result={result} />
      ) : stage === 'preview' ? (
        <BulkMovePreview
          error={flow.confirmError}
          notice={flow.notice}
          onBack={flow.back}
          onConfirm={flow.confirm}
          pageItems={pageItems}
          pending={flow.applying}
          preview={flow.preview}
        />
      ) : (
        <ModalForm
          className={styles.form}
          onSubmit={(event) => {
            event.preventDefault();
            if (valid && !flow.pending) flow.submit();
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
            <BulkMoveRows
              assets={selectionAssets(scope, pageItems)}
              draft={draft}
              onPatch={patch}
              onTick={tick}
              today={today}
            />
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
            <button className="primary-action" disabled={!valid || flow.pending} type="submit">
              {t(
                flow.applying
                  ? 'transactions.bulk.applying'
                  : isMove(draft)
                    ? 'transactions.bulk.move.preview'
                    : 'transactions.bulk.apply',
              )}
            </button>
          </ModalActions>
        </ModalForm>
      )}
    </Modal>
  );
}
