import type { AccountValuationMode } from '@cadran/api-client';
import { type AccountOrigin, originCapabilities, originKind } from './accountOrigin';
import { POSITION_KINDS } from './positionKinds';

export const VALUATION_MODES: AccountValuationMode[] = ['TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO'];

/**
 * The capability each mode consumes, mirroring the rule the API enforces. The
 * form uses it to stop offering a mode the chosen origin cannot feed; the API
 * stays the authority and refuses the combination whatever the form allows.
 */
const REQUIRED_CAPABILITY = {
  TRANSACTIONS: 'SUPPORTS_TRANSACTIONS',
  SNAPSHOTS: 'SUPPORTS_BALANCE',
  PORTFOLIO: 'SUPPORTS_HOLDINGS',
} as const;

export function productFeedsValuationMode(
  origin: AccountOrigin,
  mode: AccountValuationMode,
): boolean {
  return origin === null || originCapabilities(origin).includes(REQUIRED_CAPABILITY[mode]);
}

/**
 * The mode an origin-backed account starts on: the most faithful one the
 * catalogue product or the workspace template and its kind actually support.
 *
 * A plan that holds positions is valued by those positions — starting a share
 * savings plan on recorded movements would leave its value forever detached
 * from what it holds. Anything else starts on recorded movements, which explain
 * a balance rather than assert one.
 */
export function defaultValuationMode(origin: AccountOrigin): AccountValuationMode {
  if (origin === null) {
    return 'TRANSACTIONS';
  }

  // A workspace template declares the mode accounts created from it start on.
  // Ranking by capability would silently replace a snapshot-valued livret
  // with recorded movements just because it also supports transactions.
  if (
    origin.type === 'template' &&
    productFeedsValuationMode(origin, origin.template.valuationMode)
  ) {
    return origin.template.valuationMode;
  }

  const kind = originKind(origin);
  const preference: AccountValuationMode[] =
    kind !== null && POSITION_KINDS.includes(kind)
      ? ['PORTFOLIO', 'TRANSACTIONS', 'SNAPSHOTS']
      : ['TRANSACTIONS', 'SNAPSHOTS'];

  return preference.find((mode) => productFeedsValuationMode(origin, mode)) ?? 'TRANSACTIONS';
}
