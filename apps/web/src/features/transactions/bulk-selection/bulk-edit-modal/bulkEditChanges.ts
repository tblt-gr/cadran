import type { BulkEditChanges, PaymentMethod } from '@cadran/api-client';
import type { BulkScope } from '@/features/transactions/bulk-selection/useBulkSelection';
import type { BulkItem } from '@/features/transactions/bulk-selection/useBulkSelection';

export const MAX_LABEL_LENGTH = 140;
export const MAX_NOTE_LENGTH = 500;
export const MAX_COUNTERPARTY_LENGTH = 80;
/** A per-transaction label list stays readable and within what the server accepts. */
const MAX_EACH_ROWS = 100;

export type BulkEditField =
  'label' | 'paymentMethod' | 'note' | 'counterparty' | 'category' | 'account' | 'bookedOn';
export type LabelMode = 'SAME' | 'EACH';

/** What the user ticked and typed; a row counts only while its checkbox is ticked. */
export interface BulkEditDraft {
  accountId: string;
  bookedOn: string;
  categoryId: string;
  checked: Record<BulkEditField, boolean>;
  counterparty: string;
  /** Typed label per transaction; a transaction never typed keeps its current display label. */
  labelEach: Record<string, string>;
  labelMode: LabelMode;
  labelSame: string;
  note: string;
  /** Empty means "Aucun": the method is cleared. */
  paymentMethod: '' | PaymentMethod;
}

export const EMPTY_DRAFT: BulkEditDraft = {
  accountId: '',
  bookedOn: '',
  categoryId: '',
  checked: {
    label: false,
    paymentMethod: false,
    note: false,
    counterparty: false,
    category: false,
    account: false,
    bookedOn: false,
  },
  counterparty: '',
  labelEach: {},
  labelMode: 'SAME',
  labelSame: '',
  note: '',
  paymentMethod: '',
};

/** A move changes balances: the server asks for a preview of its impact before writing. */
export function isMove(draft: BulkEditDraft): boolean {
  return draft.checked.account || draft.checked.bookedOn;
}

/**
 * The assets of the selected rows that are loaded. A filter scope may reach rows beyond the
 * loaded page: the server then reports a different asset as a skipped row.
 */
export function selectionAssets(scope: BulkScope, items: readonly BulkItem[]): string[] {
  const selected = scope.type === 'IDS' ? new Set(scope.ids) : null;
  const codes = items
    .filter((item) => selected === null || selected.has(item.id))
    .map((item) => item.amount.assetCode);
  return [...new Set(codes)];
}

/** One label per transaction is offered for an explicit, short selection only. */
export function eachLabelAvailable(scope: BulkScope): boolean {
  return scope.type === 'IDS' && scope.ids.length <= MAX_EACH_ROWS;
}

export function eachLabelValue(draft: BulkEditDraft, item: BulkItem | undefined, id: string) {
  return draft.labelEach[id] ?? item?.displayLabel ?? '';
}

/** Ready to send: something is ticked, a ticked category is chosen, and every text fits. */
export function isDraftValid(draft: BulkEditDraft, scope: BulkScope, items: readonly BulkItem[]) {
  const ticked = Object.values(draft.checked).some(Boolean);
  if (!ticked || (draft.checked.category && draft.categoryId === '')) return false;
  if (draft.checked.account && draft.accountId === '') return false;
  if (draft.checked.bookedOn && draft.bookedOn === '') return false;
  if (draft.checked.note && draft.note.length > MAX_NOTE_LENGTH) return false;
  if (draft.checked.counterparty && draft.counterparty.length > MAX_COUNTERPARTY_LENGTH) {
    return false;
  }
  if (!draft.checked.label) return true;
  if (draft.labelMode === 'SAME') return draft.labelSame.length <= MAX_LABEL_LENGTH;
  if (scope.type !== 'IDS') return false;
  return scope.ids.every((id) => {
    const value = eachLabelValue(
      draft,
      items.find((item) => item.id === id),
      id,
    );
    return value.length <= MAX_LABEL_LENGTH;
  });
}

/** An empty note or counterparty clears the field, as the modal states. */
function textOrNull(value: string): string | null {
  return value.trim() === '' ? null : value;
}

export function buildChanges(
  draft: BulkEditDraft,
  scope: BulkScope,
  items: readonly BulkItem[],
): BulkEditChanges {
  const changes: BulkEditChanges = {};
  if (draft.checked.label) {
    changes.label =
      draft.labelMode === 'SAME' || scope.type !== 'IDS'
        ? { mode: 'SAME', value: draft.labelSame }
        : {
            mode: 'EACH',
            values: scope.ids.map((id) => ({
              id,
              value: eachLabelValue(
                draft,
                items.find((item) => item.id === id),
                id,
              ),
            })),
          };
  }
  if (draft.checked.paymentMethod) {
    changes.paymentMethod = draft.paymentMethod === '' ? null : draft.paymentMethod;
  }
  if (draft.checked.note) changes.note = textOrNull(draft.note);
  if (draft.checked.counterparty) changes.counterparty = textOrNull(draft.counterparty);
  if (draft.checked.category) changes.categoryId = draft.categoryId;
  if (draft.checked.account) changes.accountId = draft.accountId;
  if (draft.checked.bookedOn) changes.bookedOn = draft.bookedOn;
  return changes;
}
