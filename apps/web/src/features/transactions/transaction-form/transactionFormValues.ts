import type {
  Account,
  AnalyticAxes,
  CategoryType,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { categoryContradictsAmount } from '@/features/transactions/categorySign';
import { compareDecimals, isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';
import { remainingAmount } from '@/features/transactions/split-editor/splitAllocation';
import type { SplitRowValues } from '@/features/transactions/split-editor/SplitRow';

type AnalyticAxis = AnalyticAxes[number];

export type TransactionFormValues = {
  accountId: string;
  /** Axes of the single-category draft: `null` inherits the category defaults, an array (even empty) overrides them. */
  analyticAxes: AnalyticAxis[] | null;
  amountValue: string;
  authorizedOn: string;
  bankReference: string;
  bookedOn: string;
  categoryId: string;
  /** Default axes of the selected category, `null` while they are not known. */
  categoryDefaultAxes: AnalyticAxis[] | null;
  /** Type of the selected category, `null` when none is selected or it is not known. */
  categoryType: CategoryType | null;
  counterparty: string;
  maskedCard: string;
  mcc: string;
  nature: CreateTransactionRequest['nature'];
  note: string;
  paymentMethod: NonNullable<CreateTransactionRequest['paymentMethod']> | '';
  rawLabel: string;
  /** Whether the draft categorises through `splits` rather than the single `categoryId`. */
  splitMode: boolean;
  splits: SplitRowValues[];
  state: CreateTransactionRequest['state'] | 'REJECTED';
  valueOn: string;
};

export type TransactionFormErrors = Partial<Record<keyof TransactionFormValues, true>>;

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;
const OPTIONAL_LIMITS = {
  bankReference: 64,
  counterparty: 80,
  note: 500,
} as const;

export function initialTransactionValues(
  transaction: Transaction | undefined,
  accounts: Account[],
  today: string,
): TransactionFormValues {
  // A saved split was accepted only with a category matching the transaction sign,
  // and a used category can no longer change type: the sign tells its type.
  const savedCategoryType =
    transaction === undefined ? null : categoryTypeForAmount(transaction.amount.value);

  return {
    accountId: transaction?.accountId ?? accounts[0]?.id ?? '',
    analyticAxes:
      (transaction?.splits.length ?? 0) === 1 ? transaction!.splits[0]!.analyticAxes : null,
    amountValue: transaction?.amount.value ?? '',
    authorizedOn: transaction?.authorizedOn ?? '',
    bankReference: transaction?.bankReference ?? '',
    bookedOn: transaction?.bookedOn ?? today,
    categoryDefaultAxes: null,
    categoryId: transaction?.splits[0]?.categoryId ?? '',
    categoryType: transaction?.splits[0] ? savedCategoryType : null,
    counterparty: transaction?.counterparty ?? '',
    maskedCard: transaction?.maskedCard ?? '',
    mcc: transaction?.mcc ?? '',
    nature: standaloneNature(transaction?.nature),
    note: transaction?.note ?? '',
    paymentMethod: transaction?.paymentMethod ?? '',
    rawLabel: transaction?.rawLabel ?? '',
    splitMode: (transaction?.splits.length ?? 0) > 1,
    splits: (transaction?.splits ?? []).map((split) => ({
      amount: split.amount.value,
      analyticAxes: split.analyticAxes,
      categoryColor: split.categoryColor,
      categoryIcon: split.categoryIcon,
      categoryId: split.categoryId,
      categoryLabel: split.categoryLabel,
      categoryType: savedCategoryType,
      key: split.id,
      note: split.note ?? '',
    })),
    state:
      transaction?.state === 'PENDING' || transaction?.state === 'REJECTED'
        ? transaction.state
        : 'BOOKED',
    valueOn: transaction?.valueOn ?? '',
  };
}

export function validateTransactionValues(
  values: TransactionFormValues,
  accounts: Account[],
  today: string,
  transaction?: Transaction,
): TransactionFormErrors {
  const errors: TransactionFormErrors = {};
  const account = accounts.find((candidate) => candidate.id === values.accountId);
  const editingCurrentAccount = transaction?.accountId === values.accountId;
  const amount = values.amountValue;
  const exactAmount = isCanonicalDecimal(amount);

  if (!editingCurrentAccount && (account === undefined || account.status !== 'ACTIVE')) {
    errors.accountId = true;
  }

  if (!exactAmount || isZeroDecimal(amount)) {
    errors.amountValue = true;
  } else {
    const negative = compareDecimals(amount, '0') < 0;
    if (
      (values.nature === 'INCOME' && negative) ||
      ((values.nature === 'EXPENSE' || values.nature === 'FEE') && !negative)
    ) {
      errors.amountValue = true;
      errors.nature = true;
    }
  }

  if (!ISO_DAY.test(values.bookedOn) || values.bookedOn > today) {
    errors.bookedOn = true;
  } else if (account !== undefined && values.bookedOn < account.openedOn) {
    errors.bookedOn = true;
  }

  if (
    values.authorizedOn !== '' &&
    (!ISO_DAY.test(values.authorizedOn) || values.authorizedOn > values.bookedOn)
  ) {
    errors.authorizedOn = true;
  }

  if (
    values.valueOn !== '' &&
    (!ISO_DAY.test(values.valueOn) || !withinDays(values.bookedOn, values.valueOn, 90))
  ) {
    errors.valueOn = true;
  }

  const rawLabel = values.rawLabel;
  if (rawLabel !== rawLabel.trim() || rawLabel.length === 0 || [...rawLabel].length > 140) {
    errors.rawLabel = true;
  }

  for (const [field, limit] of Object.entries(OPTIONAL_LIMITS) as Array<
    [keyof typeof OPTIONAL_LIMITS, number]
  >) {
    const value = values[field];
    if (value !== '' && (value !== value.trim() || [...value].length > limit)) {
      errors[field] = true;
    }
  }

  if (values.mcc !== '' && !/^\d{4}$/.test(values.mcc)) {
    errors.mcc = true;
  }
  if (values.maskedCard !== '' && !/^\d{4}$/.test(values.maskedCard)) {
    errors.maskedCard = true;
  }

  if (
    !values.splitMode &&
    values.categoryId !== '' &&
    exactAmount &&
    categoryContradictsAmount(values.categoryType, amount)
  ) {
    errors.categoryId = true;
  }

  if (values.splitMode && values.splits.length > 0 && !splitsAreBalanced(values)) {
    errors.splits = true;
  }

  return errors;
}

function splitsAreBalanced(values: TransactionFormValues): boolean {
  if (!isCanonicalDecimal(values.amountValue)) {
    return false;
  }

  const totalNegative = compareDecimals(values.amountValue, '0') < 0;
  const categoryIds = values.splits.map((row) => row.categoryId);
  const hasDuplicateCategory = categoryIds.some(
    (id, index) => id !== '' && categoryIds.indexOf(id) !== index,
  );
  const hasIncompleteRow = values.splits.some(
    (row) =>
      row.categoryId === '' ||
      categoryContradictsAmount(row.categoryType ?? null, values.amountValue) ||
      !isCanonicalDecimal(row.amount) ||
      isZeroDecimal(row.amount) ||
      compareDecimals(row.amount, '0') < 0 !== totalNegative,
  );
  if (hasDuplicateCategory || hasIncompleteRow) {
    return false;
  }

  const remaining = remainingAmount(
    values.amountValue,
    values.splits.map((row) => row.amount),
  );

  return isZeroDecimal(remaining);
}

export function transactionRequest(
  values: TransactionFormValues,
  accounts: Account[],
  transaction?: Transaction,
): CreateTransactionRequest | UpdateTransactionRequest {
  const account = accounts.find((candidate) => candidate.id === values.accountId);
  const assetCode = transaction?.amount.assetCode ?? account?.assetCode ?? '';
  const common = {
    accountId: values.accountId,
    amount: { value: values.amountValue, assetCode },
    nature: values.nature,
    bookedOn: values.bookedOn,
    valueOn: optional(values.valueOn),
    authorizedOn: optional(values.authorizedOn),
    rawLabel: values.rawLabel,
    counterparty: optional(values.counterparty),
    note: optional(values.note),
    paymentMethod: values.paymentMethod || null,
    mcc: optional(values.mcc),
    maskedCard: optional(values.maskedCard),
    bankReference: optional(values.bankReference),
    ...categorisation(values, assetCode, transaction),
  };

  if (transaction !== undefined) {
    return { ...common, state: values.state, version: transaction.version };
  }

  return {
    ...common,
    state: values.state === 'REJECTED' ? 'BOOKED' : values.state,
  };
}

/**
 * A single category alone inherits its defaults on the server, so explicit axes
 * travel as one split covering the whole amount. The saved split's note is kept
 * when the category is unchanged, as the single-category path would.
 */
function categorisation(
  values: TransactionFormValues,
  assetCode: string,
  transaction: Transaction | undefined,
): Pick<CreateTransactionRequest, 'categoryId' | 'splits'> {
  if (values.splitMode) {
    return {
      categoryId: null,
      splits: values.splits.map((row) => ({
        categoryId: row.categoryId,
        amount: { value: row.amount, assetCode },
        analyticAxes: row.analyticAxes,
        note: optional(row.note),
      })),
    };
  }

  const saved = transaction?.splits.length === 1 ? transaction.splits[0] : undefined;
  const sameCategory = saved?.categoryId === values.categoryId;
  const unchanged =
    sameCategory &&
    values.analyticAxes !== null &&
    sameAxes(saved?.analyticAxes ?? [], values.analyticAxes);

  if (values.categoryId === '' || values.analyticAxes === null || unchanged) {
    return { categoryId: values.categoryId || null, splits: null };
  }

  return {
    categoryId: null,
    splits: [
      {
        categoryId: values.categoryId,
        amount: { value: values.amountValue, assetCode },
        analyticAxes: values.analyticAxes,
        note: sameCategory ? (saved?.note ?? null) : null,
      },
    ],
  };
}

function sameAxes(left: AnalyticAxis[], right: AnalyticAxis[]): boolean {
  return left.length === right.length && left.every((axis) => right.includes(axis));
}

export function categoryTypeForAmount(
  amount: string,
  nature: TransactionFormValues['nature'] = 'EXPENSE',
): 'EXPENSE' | 'INCOME' {
  if (amount.trim() === '') {
    return nature === 'INCOME' ? 'INCOME' : 'EXPENSE';
  }

  return amount.startsWith('-') ? 'EXPENSE' : 'INCOME';
}

function optional(value: string): string | null {
  return value === '' ? null : value;
}

function standaloneNature(
  nature: Transaction['nature'] | undefined,
): CreateTransactionRequest['nature'] {
  if (nature === 'TRANSFER' || nature === 'REFUND') {
    throw new Error('Linked transactions require their dedicated editor.');
  }

  return nature ?? 'EXPENSE';
}

function withinDays(reference: string, candidate: string, maximum: number): boolean {
  const referenceDay = Date.parse(`${reference}T12:00:00Z`);
  const candidateDay = Date.parse(`${candidate}T12:00:00Z`);
  if (!Number.isFinite(referenceDay) || !Number.isFinite(candidateDay)) {
    return false;
  }

  return Math.abs(candidateDay - referenceDay) <= maximum * 86_400_000;
}
