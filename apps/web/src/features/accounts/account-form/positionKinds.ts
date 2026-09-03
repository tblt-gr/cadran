import type { AccountKind } from '@cadran/api-client';

/**
 * Mirrors the domain rule: only these kinds hold positions, so only they accept
 * a portfolio valuation. Kept beside the form so the interface never offers a
 * combination the backend refuses.
 */
export const POSITION_KINDS: AccountKind[] = [
  'PORTFOLIO',
  'INSURANCE_CONTRACT',
  'EMPLOYEE_BENEFIT',
];
