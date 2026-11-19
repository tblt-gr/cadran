import type {
  Account,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useCategoryDefaultAxes } from '@/features/categories/useCategoryDefaultAxes';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { addDecimals, negateDecimal } from '@/lib/decimal';
import { isBalanceGuarded, wouldGoNegative } from '@/features/transactions/wouldGoNegative';
import { isUpcomingDay, workspaceToday } from '@/lib/workspaceTime';
import { ADVANCED_FIELDS } from './advanced-fields/advancedFields';
import type { SavedCategory } from './transaction-category-field/TransactionCategoryField';
import {
  categoryTypeForAmount,
  initialTransactionValues,
  signedTransactionValues,
  transactionDirection,
  transactionRequest,
  validateTransactionValues,
  type TransactionFormValues,
} from './transactionFormValues';

export interface TransactionFormDefaults {
  bookedOn: string;
  category: SavedCategory & { type: TransactionFormValues['categoryType'] };
  nature: TransactionFormValues['nature'];
}

interface TransactionFormStateInput {
  accounts: Account[];
  defaultAccountId?: string;
  defaults?: TransactionFormDefaults;
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  transaction?: Transaction;
}

function savedCategoryOf(transaction: Transaction | undefined): SavedCategory | null {
  const split = transaction?.splits[0];

  return split
    ? {
        color: split.categoryColor,
        icon: split.categoryIcon,
        id: split.categoryId,
        label: split.categoryLabel,
      }
    : null;
}

/**
 * Owns the draft of a transaction form: the typed values, the disclosure and error display
 * flags, everything derived from them, and the validation run on submit.
 */
export function useTransactionFormState({
  accounts,
  defaultAccountId,
  defaults,
  onSubmit,
  transaction,
}: TransactionFormStateInput) {
  const workspaceTimeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), workspaceTimeZone);
  const [values, setValues] = useState(() => {
    const initial = initialTransactionValues(transaction, accounts, defaults?.bookedOn ?? today);

    return transaction === undefined && defaults
      ? {
          ...initial,
          bookedOn: defaults.bookedOn,
          categoryId: defaults.category.id,
          categoryType: defaults.category.type,
          nature: defaults.nature,
          // An income added from the budget is nearly always received by transfer.
          paymentMethod:
            defaults.nature === 'INCOME' ? ('TRANSFER' as const) : initial.paymentMethod,
        }
      : initial;
  });
  const [showErrors, setShowErrors] = useState(false);
  const [accountChosen, setAccountChosen] = useState(false);
  const savedCategory = savedCategoryOf(transaction) ?? defaults?.category ?? null;
  // Category allocation stays beside the amount; metadata keeps its own disclosure.
  const categoryDefaultAxes = useCategoryDefaultAxes(
    values.categoryId,
    savedCategory?.label ?? null,
    values.categoryDefaultAxes,
  );
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const withAccount =
    !transaction && !accountChosen
      ? {
          ...values,
          accountId:
            accounts.find(
              (account) => account.id === defaultAccountId && account.status === 'ACTIVE',
            )?.id ??
            accounts.find((account) => account.isPrimary && account.status === 'ACTIVE')?.id ??
            '',
        }
      : values;
  // A date later this month can only be a planned (pending) row: the chosen state is kept aside
  // and comes back when the date returns to today or earlier. A booked row stays bound to today.
  const upcoming =
    isUpcomingDay(values.bookedOn, today) &&
    transaction?.state !== 'BOOKED' &&
    values.state !== 'REJECTED';
  const resolved = upcoming ? { ...withAccount, state: 'PENDING' as const } : withAccount;
  const errors = validateTransactionValues(resolved, accounts, today, transaction);
  const editing = transaction !== undefined;
  const direction = transactionDirection(values);
  const signedAmount = signedTransactionValues(values).amountValue;
  const amountAssetCode =
    transaction?.amount.assetCode ??
    accounts.find((account) => account.id === resolved.accountId)?.assetCode ??
    '';

  // On creation only: an existing row is already counted in the reported balance.
  const accountBalance = accounts.find((account) => account.id === resolved.accountId)?.valuation
    ?.amount;
  // The server stays the authority for backdated and future dates: the hint only covers a booking
  // dated today or earlier, where the reported balance is the one the entry lands on.
  const shortfallDay =
    !editing &&
    !errors.amountValue &&
    accountBalance &&
    accountBalance.assetCode === amountAssetCode &&
    isBalanceGuarded(accounts.find((account) => account.id === resolved.accountId)) &&
    values.bookedOn !== '' &&
    values.bookedOn <= today &&
    wouldGoNegative(
      accountBalance.value,
      signedAmount.startsWith('-') ? negateDecimal(signedAmount) : '0',
    )
      ? values.bookedOn
      : null;
  const balanceAfter =
    shortfallDay === null &&
    !editing &&
    !errors.amountValue &&
    accountBalance &&
    accountBalance.assetCode === amountAssetCode
      ? {
          value: addDecimals(accountBalance.value, signedAmount),
          assetCode: accountBalance.assetCode,
        }
      : null;

  function set<K extends keyof TransactionFormValues>(field: K, value: TransactionFormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    if (Object.keys(errors).length > 0) {
      setShowErrors(true);
      // An invalid field must never stay folded out of sight.
      if (ADVANCED_FIELDS.some((field) => errors[field])) {
        setAdvancedOpen(true);
      }
      return;
    }

    onSubmit(transactionRequest(resolved, accounts, transaction));
  }

  return {
    advancedOpen,
    amountAssetCode,
    balanceAfter,
    categoryDefaultAxes,
    direction,
    editing,
    errors,
    markAccountChosen: () => setAccountChosen(true),
    preferredType: categoryTypeForAmount(signedAmount, values.nature),
    rawLabelLocked: editing && transaction.source !== 'MANUAL',
    resolved,
    savedCategory,
    set,
    setAdvancedOpen,
    setValues,
    shortfallDay,
    showErrors,
    signedAmount,
    stateLocked: editing && transaction.state !== 'PENDING',
    submit,
    upcoming,
    values,
  };
}
