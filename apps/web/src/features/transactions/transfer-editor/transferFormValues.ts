import type { Account, CreateTransferRequest } from '@cadran/api-client';
import { compareDecimals, isCanonicalDecimal, isZeroDecimal, negateDecimal } from '@/lib/decimal';

export type TransferFormValues = {
  sourceAccountId: string;
  targetAccountId: string;
  sourceAmountValue: string;
  targetAmountValue: string;
  bookedOn: string;
  valueOn: string;
  label: string;
  note: string;
  hasFee: boolean;
  feeValue: string;
  state: 'BOOKED' | 'PENDING';
};

export type TransferFormErrors = Partial<Record<keyof TransferFormValues, true>>;

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

export function initialTransferValues(accounts: Account[], today: string): TransferFormValues {
  const active = accounts.filter((account) => account.status === 'ACTIVE');

  return {
    sourceAccountId: active[0]?.id ?? '',
    targetAccountId: active.find((account) => account.id !== active[0]?.id)?.id ?? '',
    sourceAmountValue: '',
    targetAmountValue: '',
    bookedOn: today,
    valueOn: '',
    label: '',
    note: '',
    hasFee: false,
    feeValue: '',
    state: 'BOOKED',
  };
}

/** The form shows a second amount field only once the two accounts differ in asset. */
export function isCrossAssetTransfer(values: TransferFormValues, accounts: Account[]): boolean {
  const source = accounts.find((account) => account.id === values.sourceAccountId);
  const target = accounts.find((account) => account.id === values.targetAccountId);

  return source !== undefined && target !== undefined && source.assetCode !== target.assetCode;
}

export function validateTransferValues(
  values: TransferFormValues,
  accounts: Account[],
  today: string,
): TransferFormErrors {
  const errors: TransferFormErrors = {};
  const source = accounts.find((account) => account.id === values.sourceAccountId);
  const target = accounts.find((account) => account.id === values.targetAccountId);
  const crossAsset = isCrossAssetTransfer(values, accounts);

  if (source === undefined || source.status !== 'ACTIVE') {
    errors.sourceAccountId = true;
  }
  if (
    target === undefined ||
    target.status !== 'ACTIVE' ||
    values.targetAccountId === values.sourceAccountId
  ) {
    errors.targetAccountId = true;
  }

  if (!isPositiveMagnitude(values.sourceAmountValue)) {
    errors.sourceAmountValue = true;
  }
  if (crossAsset && !isPositiveMagnitude(values.targetAmountValue)) {
    errors.targetAmountValue = true;
  }

  if (!ISO_DAY.test(values.bookedOn) || values.bookedOn > today) {
    errors.bookedOn = true;
  } else if (
    (source !== undefined && values.bookedOn < source.openedOn) ||
    (target !== undefined && values.bookedOn < target.openedOn)
  ) {
    errors.bookedOn = true;
  }

  if (
    values.valueOn !== '' &&
    (!ISO_DAY.test(values.valueOn) || !withinDays(values.bookedOn, values.valueOn, 90))
  ) {
    errors.valueOn = true;
  }

  const label = values.label;
  if (label !== label.trim() || label.length === 0 || [...label].length > 140) {
    errors.label = true;
  }

  if (values.note !== '' && (values.note !== values.note.trim() || [...values.note].length > 500)) {
    errors.note = true;
  }

  if (values.hasFee && !isPositiveMagnitude(values.feeValue)) {
    errors.feeValue = true;
  }

  return errors;
}

export function transferRequest(
  values: TransferFormValues,
  accounts: Account[],
): CreateTransferRequest {
  const source = accounts.find((account) => account.id === values.sourceAccountId);
  const target = accounts.find((account) => account.id === values.targetAccountId);
  const crossAsset = isCrossAssetTransfer(values, accounts);

  return {
    sourceAccountId: values.sourceAccountId,
    targetAccountId: values.targetAccountId,
    sourceAmount: { value: values.sourceAmountValue, assetCode: source?.assetCode ?? '' },
    targetAmount: crossAsset
      ? { value: values.targetAmountValue, assetCode: target?.assetCode ?? '' }
      : null,
    state: values.state,
    bookedOn: values.bookedOn,
    valueOn: optional(values.valueOn),
    label: values.label,
    note: optional(values.note),
    fee: values.hasFee
      ? { value: negateDecimal(values.feeValue), assetCode: source?.assetCode ?? '' }
      : null,
  };
}

function isPositiveMagnitude(value: string): boolean {
  return isCanonicalDecimal(value) && !isZeroDecimal(value) && compareDecimals(value, '0') > 0;
}

function optional(value: string): string | null {
  return value === '' ? null : value;
}

function withinDays(reference: string, candidate: string, maximum: number): boolean {
  const referenceDay = Date.parse(`${reference}T12:00:00Z`);
  const candidateDay = Date.parse(`${candidate}T12:00:00Z`);
  if (!Number.isFinite(referenceDay) || !Number.isFinite(candidateDay)) {
    return false;
  }

  return Math.abs(candidateDay - referenceDay) <= maximum * 86_400_000;
}
