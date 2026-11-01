import type {
  Account,
  CategoryType,
  PaymentMethod,
  Recurrence,
  RecurrenceCandidate,
  RecurrenceIntervalKind,
} from '@cadran/api-client';
import { isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';
import { toTypedAmount, type AmountDirection } from '@/lib/signedAmount';

function defaultAccount(accounts: Account[]): Account | undefined {
  return (
    accounts.find((account) => account.isPrimary && account.status === 'ACTIVE') ?? accounts[0]
  );
}

export interface FormValues {
  accountId: string;
  amountTolerance: string;
  autoCreate: boolean;
  categoryId: string;
  /** Type of the chosen category, known once the picker or the saved record told it. */
  categoryType: CategoryType | null;
  categoryLabel: string | null;
  counterparty: string;
  /** Empty keeps the sign as typed; a chosen direction lets the amount be typed without it. */
  direction: AmountDirection | '';
  dayOfPeriod: string;
  expectedAmount: string;
  firstExpectedOn: string;
  intervalKind: RecurrenceIntervalKind;
  label: string;
  note: string;
  paymentMethod: PaymentMethod | '';
}

export const INTERVALS: RecurrenceIntervalKind[] = ['WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY'];

/** The category type an amount direction accepts. */
export function categoryTypeFor(direction: AmountDirection | ''): CategoryType | undefined {
  if (direction === '') return undefined;

  return direction === 'OUTFLOW' ? 'EXPENSE' : 'INCOME';
}

/** The direction an already signed amount implies; none for a blank or zero amount. */
function directionOf(signed: string): AmountDirection | '' {
  if (!isCanonicalDecimal(signed) || isZeroDecimal(signed)) return '';

  return signed.startsWith('-') ? 'OUTFLOW' : 'INFLOW';
}

export function initialValues(
  accounts: Account[],
  candidate?: RecurrenceCandidate,
  recurrence?: Recurrence,
): FormValues {
  if (recurrence) {
    const direction = directionOf(recurrence.expectedAmount.value);

    return {
      accountId: recurrence.accountId,
      amountTolerance: recurrence.amountTolerance.value,
      autoCreate: recurrence.autoCreate,
      categoryId: recurrence.categoryId ?? '',
      categoryLabel: null,
      categoryType: null,
      counterparty: recurrence.counterparty ?? '',
      dayOfPeriod: String(recurrence.dayOfPeriod),
      direction,
      expectedAmount: toTypedAmount(recurrence.expectedAmount.value, direction || null),
      firstExpectedOn: recurrence.nextExpectedOn,
      intervalKind: recurrence.intervalKind,
      label: recurrence.label,
      note: recurrence.note ?? '',
      paymentMethod: recurrence.paymentMethod ?? '',
    };
  }

  const candidateDirection = directionOf(candidate?.medianAmount.value ?? '');

  return {
    accountId: candidate?.accountId ?? defaultAccount(accounts)?.id ?? '',
    amountTolerance: candidate?.tolerance.value ?? '0',
    autoCreate: false,
    categoryId: '',
    categoryLabel: null,
    categoryType: null,
    counterparty: candidate?.counterparty ?? '',
    dayOfPeriod: '',
    direction: candidateDirection,
    expectedAmount: toTypedAmount(candidate?.medianAmount.value ?? '', candidateDirection || null),
    firstExpectedOn: candidate?.lastSeenOn ?? '',
    intervalKind: candidate?.intervalKind ?? 'MONTHLY',
    label: candidate?.counterparty ?? '',
    note: '',
    paymentMethod: '',
  };
}

export function validDay(value: string, interval: RecurrenceIntervalKind): boolean {
  if (!/^[0-9]+$/.test(value)) return false;

  const day = Number(value);
  return interval === 'WEEKLY' ? day >= 1 && day <= 7 : day >= 1 && day <= 31;
}

/**
 * The values after the amount direction changed: a chosen category of the other type is
 * cleared, since the server refuses the pairing.
 */
export function withDirection(
  current: FormValues,
  next: FormValues['direction'],
  categoryType: CategoryType | null,
): FormValues {
  const incompatible =
    current.categoryId !== '' &&
    categoryType !== null &&
    next !== '' &&
    categoryType !== categoryTypeFor(next);

  return {
    ...current,
    categoryId: incompatible ? '' : current.categoryId,
    categoryLabel: incompatible ? null : current.categoryLabel,
    categoryType: incompatible ? null : current.categoryType,
    direction: next,
  };
}
