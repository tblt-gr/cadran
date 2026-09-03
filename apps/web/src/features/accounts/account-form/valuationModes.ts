import type { AccountValuationMode, Product } from '@cadran/api-client';
import { POSITION_KINDS } from './positionKinds';

export const VALUATION_MODES: AccountValuationMode[] = ['TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO'];

/**
 * The capability each mode consumes, mirroring the rule the API enforces. The
 * form uses it to stop offering a mode the chosen product cannot feed; the API
 * stays the authority and refuses the combination whatever the form allows.
 */
const REQUIRED_CAPABILITY = {
  TRANSACTIONS: 'SUPPORTS_TRANSACTIONS',
  SNAPSHOTS: 'SUPPORTS_BALANCE',
  PORTFOLIO: 'SUPPORTS_HOLDINGS',
} as const;

export function productFeedsValuationMode(
  product: Product | null,
  mode: AccountValuationMode,
): boolean {
  return product === null || product.capabilities.includes(REQUIRED_CAPABILITY[mode]);
}

/**
 * The mode a product-backed account starts on: the most faithful one the
 * product and its kind actually support.
 *
 * A plan that holds positions is valued by those positions — starting a share
 * savings plan on recorded movements would leave its value forever detached
 * from what it holds. Anything else starts on recorded movements, which explain
 * a balance rather than assert one.
 */
export function defaultValuationMode(product: Product | null): AccountValuationMode {
  if (product === null) {
    return 'TRANSACTIONS';
  }

  const preference: AccountValuationMode[] = POSITION_KINDS.includes(product.accountKind)
    ? ['PORTFOLIO', 'TRANSACTIONS', 'SNAPSHOTS']
    : ['TRANSACTIONS', 'SNAPSHOTS'];

  return preference.find((mode) => productFeedsValuationMode(product, mode)) ?? 'TRANSACTIONS';
}
