import type {
  CreateRecurrenceRequest,
  Recurrence,
  UpdateRecurrenceRequest,
} from '@cadran/api-client';
import type { FormValues } from './recurrenceFormValues';

/** The create or update body; `expectedAmount` is the signed canonical amount already derived. */
export function recurrenceRequest(
  values: FormValues,
  expectedAmount: string,
  recurrence: Recurrence | undefined,
): CreateRecurrenceRequest | UpdateRecurrenceRequest {
  const shared = {
    amountTolerance: values.amountTolerance,
    autoCreate: values.autoCreate,
    categoryId: values.categoryId === '' ? null : values.categoryId,
    counterparty: values.counterparty.trim() || null,
    dayOfPeriod: Number(values.dayOfPeriod),
    expectedAmount,
    intervalKind: values.intervalKind,
    label: values.label.trim(),
    note: values.note.trim() === '' ? null : values.note.trim(),
    paymentMethod: values.paymentMethod === '' ? null : values.paymentMethod,
  };

  return recurrence
    ? { ...shared, firstExpectedOn: values.firstExpectedOn, version: recurrence.version }
    : { ...shared, accountId: values.accountId, firstExpectedOn: values.firstExpectedOn };
}
