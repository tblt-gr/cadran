import type { Account } from '@cadran/api-client';
import { addDecimals, subtractDecimals } from '@/lib/decimal';
import { isBalanceGuarded, wouldGoNegative } from '@/features/transactions/wouldGoNegative';
import type { TransferFormValues, TransferFormErrors } from './transferFormValues';

interface Balance {
  value: string;
  assetCode: string;
}

interface BalancesAfterTransferInput {
  crossAsset: boolean;
  destination: Account | undefined;
  errors: TransferFormErrors;
  source: Account | undefined;
  values: TransferFormValues;
}

/** The exact balance the server reports for an account, when it can be calculated. */
export const balanceOf = (account: Account | undefined): Balance | null =>
  account?.valuation?.amount ?? null;

/** Once the amount is valid, each account shows what its balance becomes: the same exact decimals. */
export function balancesAfterTransfer({
  crossAsset,
  destination,
  errors,
  source,
  values,
}: BalancesAfterTransferInput): { sourceAfter: Balance | null; targetAfter: Balance | null } {
  const amountReady = values.sourceAmountValue !== '' && !errors.sourceAmountValue;
  const targetAmountReady = values.targetAmountValue !== '' && !errors.targetAmountValue;
  const sourceBalance = balanceOf(source);
  const targetBalance = balanceOf(destination);
  const sourceAfter =
    amountReady && sourceBalance !== null && sourceBalance.assetCode === source?.assetCode
      ? {
          value: subtractDecimals(sourceBalance.value, values.sourceAmountValue),
          assetCode: sourceBalance.assetCode,
        }
      : null;
  const targetMoved = crossAsset
    ? targetAmountReady
      ? values.targetAmountValue
      : null
    : amountReady
      ? values.sourceAmountValue
      : null;
  const targetAfter =
    targetMoved !== null &&
    targetBalance !== null &&
    targetBalance.assetCode === destination?.assetCode
      ? { value: addDecimals(targetBalance.value, targetMoved), assetCode: targetBalance.assetCode }
      : null;

  return { sourceAfter, targetAfter };
}

/**
 * The amount would take the source account below zero. The server stays the authority for backdated
 * and future dates: the hint only covers a transfer dated today or earlier, where the reported balance
 * is the one the transfer lands on.
 */
export function transferOverdraws({
  errors,
  source,
  today,
  values,
}: {
  errors: TransferFormErrors;
  source: Account | undefined;
  today: string;
  values: TransferFormValues;
}): boolean {
  const balance = balanceOf(source);

  return (
    isBalanceGuarded(source) &&
    balance !== null &&
    balance.assetCode === source?.assetCode &&
    values.sourceAmountValue !== '' &&
    !errors.sourceAmountValue &&
    values.bookedOn !== '' &&
    values.bookedOn <= today &&
    wouldGoNegative(balance.value, values.sourceAmountValue)
  );
}
