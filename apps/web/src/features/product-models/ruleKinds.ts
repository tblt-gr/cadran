import type {
  ProductCapability,
  ProductRuleKind,
  ProductYieldKind,
  RuleValueType,
} from '@cadran/api-client';

/**
 * The frontend copy of the rule taxonomy the domain owns. It never decides a
 * financial value; it only keeps the form from offering a period the backend
 * would refuse — a rate on a market model, a ceiling without the capability
 * that measures it. The backend stays the authority and re-checks every case.
 */

export const RULE_KINDS: ProductRuleKind[] = [
  'DEPOSIT_CEILING',
  'BALANCE_CEILING',
  'CONTRIBUTION_CEILING',
  'COMBINED_CONTRIBUTION_CEILING',
  'ANNUAL_RATE',
  'MIN_RATE',
  'INTEREST_ACCRUAL_METHOD',
  'ELIGIBILITY',
  'TAX_REFERENCE',
];

const VALUE_TYPE: Record<ProductRuleKind, RuleValueType> = {
  DEPOSIT_CEILING: 'AMOUNT',
  BALANCE_CEILING: 'AMOUNT',
  CONTRIBUTION_CEILING: 'AMOUNT',
  COMBINED_CONTRIBUTION_CEILING: 'AMOUNT',
  ANNUAL_RATE: 'PERCENTAGE',
  MIN_RATE: 'PERCENTAGE',
  INTEREST_ACCRUAL_METHOD: 'TEXT',
  ELIGIBILITY: 'TEXT',
  TAX_REFERENCE: 'TEXT',
};

const REQUIRED_CAPABILITY: Record<ProductRuleKind, ProductCapability | null> = {
  DEPOSIT_CEILING: 'SUPPORTS_BALANCE',
  BALANCE_CEILING: 'SUPPORTS_BALANCE',
  CONTRIBUTION_CEILING: 'SUPPORTS_CONTRIBUTIONS',
  COMBINED_CONTRIBUTION_CEILING: 'SUPPORTS_CONTRIBUTIONS',
  ANNUAL_RATE: 'SUPPORTS_INTEREST',
  MIN_RATE: 'SUPPORTS_INTEREST',
  INTEREST_ACCRUAL_METHOD: 'SUPPORTS_INTEREST',
  ELIGIBILITY: null,
  TAX_REFERENCE: 'SUPPORTS_TAX_TRACKING',
};

/** Only a regulated or contractual yield may carry a rate period at all. */
const YIELD_ACCEPTS_RATE: Record<ProductYieldKind, boolean> = {
  NONE: false,
  REGULATED_RATE: true,
  CONTRACTUAL_FIXED: true,
  CONTRACTUAL_VARIABLE: true,
  MARKET: false,
  MANUAL_VALUATION: false,
};

export function ruleValueType(kind: ProductRuleKind): RuleValueType {
  return VALUE_TYPE[kind];
}

export function ruleRequiredCapability(kind: ProductRuleKind): ProductCapability | null {
  return REQUIRED_CAPABILITY[kind];
}

export function yieldAcceptsRateRule(yieldKind: ProductYieldKind): boolean {
  return YIELD_ACCEPTS_RATE[yieldKind];
}

/**
 * Whether a period of this kind can be offered for a model with this yield and
 * these capabilities. A rate needs a yield that owes one; every kind needs the
 * capability that gives its value a meaning.
 */
export function ruleKindAvailable(
  kind: ProductRuleKind,
  yieldKind: ProductYieldKind,
  capabilities: readonly ProductCapability[],
): boolean {
  if (ruleValueType(kind) === 'PERCENTAGE' && !yieldAcceptsRateRule(yieldKind)) {
    return false;
  }

  const required = ruleRequiredCapability(kind);

  return required === null || capabilities.includes(required);
}
