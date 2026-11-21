import type { NetWorthAmount } from '@cadran/api-client';

/** Prefer server-owned display precision, retaining exact asset values as fallback. */
export function shownAmount(amount: NetWorthAmount): { value: string; assetCode: string } {
  return amount.display ?? { value: amount.value, assetCode: amount.assetCode };
}
