import type { Account, Product, ProductModel } from '@cadran/api-client';
import { compareDecimals, decimalRatioForGeometry } from './decimal';

const CEILING_KINDS = new Set([
  'BALANCE_CEILING',
  'COMBINED_CONTRIBUTION_CEILING',
  'CONTRIBUTION_CEILING',
  'DEPOSIT_CEILING',
]);

/**
 * The published max the account's origin states, if any.
 */
export function depositCeilingOf(
  account: Account,
  products: readonly Product[],
  models: readonly ProductModel[],
): { assetCode: string; value: string } | null {
  if (account.productCode !== null) {
    const product = products.find((item) => item.code === account.productCode);
    return amountCeiling(product?.rules);
  }

  if (account.productModelId !== null) {
    const model = models.find((item) => item.id === account.productModelId);
    return amountCeiling(model?.rules);
  }

  return null;
}

/** Pixel fill only. The printed pair stays the two backend display strings. */
export function ceilingFillPercent(current: string, ceiling: string): number | null {
  if (compareDecimals(ceiling, '0') <= 0) {
    return null;
  }
  if (compareDecimals(current, '0') <= 0) {
    return 0;
  }
  if (compareDecimals(current, ceiling) >= 0) {
    return 100;
  }

  return decimalRatioForGeometry(current, ceiling) * 100;
}

function amountCeiling(
  rules:
    | ReadonlyArray<{ amount?: { assetCode: string; value: string } | null; kind: string }>
    | undefined,
): { assetCode: string; value: string } | null {
  const rule = rules?.find((item) => CEILING_KINDS.has(item.kind) && item.amount !== null);
  return rule?.amount ?? null;
}
