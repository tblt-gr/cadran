import type { Account } from '@cadran/api-client';
import { addDecimals, subtractDecimals } from '@/lib/decimal';
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
