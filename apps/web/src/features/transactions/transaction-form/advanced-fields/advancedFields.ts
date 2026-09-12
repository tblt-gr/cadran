import type { TransactionFormValues } from '@/features/transactions/transaction-form/transactionFormValues';

/** The fields a manual entry rarely needs, folded under the essential ones. */
export const LABELLED_FIELDS = [
  'counterparty',
  'note',
  'state',
  'valueOn',
  'authorizedOn',
] as const satisfies ReadonlyArray<keyof TransactionFormValues>;

export type LabelledField = (typeof LABELLED_FIELDS)[number];

/** Every field of the section, the split included: an error on any of them opens it. */
export const ADVANCED_FIELDS: ReadonlyArray<keyof TransactionFormValues> = [
  ...LABELLED_FIELDS,
  'splits',
];
