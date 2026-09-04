import type {
  AccountKind,
  AccountValuationMode,
  CreateProductModelRequest,
  Product,
  ProductCapability,
  ProductWrapperKind,
  ProductYieldKind,
} from '@cadran/api-client';

/** The raw field state of the model form, before validation. */
export interface ModelFormValues {
  name: string;
  family: AccountKind;
  wrapperKind: ProductWrapperKind;
  yieldKind: ProductYieldKind;
  defaultGroupCode: string;
  valuationMode: AccountValuationMode;
  capabilities: ProductCapability[];
}

export const ACCOUNT_KINDS: AccountKind[] = [
  'CURRENT',
  'SAVINGS',
  'PORTFOLIO',
  'INSURANCE_CONTRACT',
  'EMPLOYEE_BENEFIT',
  'CASH',
  'REAL_ASSET',
  'LIABILITY',
];

export const WRAPPER_KINDS: ProductWrapperKind[] = [
  'NONE',
  'REGULATED_SAVINGS',
  'TAX_WRAPPER',
  'SECURITIES_ACCOUNT',
  'LIFE_INSURANCE',
  'RETIREMENT',
  'EMPLOYEE_SAVINGS',
];

export const YIELD_KINDS: ProductYieldKind[] = [
  'NONE',
  'REGULATED_RATE',
  'CONTRACTUAL_FIXED',
  'CONTRACTUAL_VARIABLE',
  'MARKET',
  'MANUAL_VALUATION',
];

export const VALUATION_MODES: AccountValuationMode[] = ['TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO'];

export const CAPABILITIES: ProductCapability[] = [
  'SUPPORTS_BALANCE',
  'SUPPORTS_TRANSACTIONS',
  'SUPPORTS_INTEREST',
  'SUPPORTS_HOLDINGS',
  'SUPPORTS_TRADES',
  'SUPPORTS_ARBITRAGE',
  'SUPPORTS_CONTRIBUTIONS',
  'SUPPORTS_FEES',
  'SUPPORTS_TAX_TRACKING',
  'SUPPORTS_LIABILITY',
];

/**
 * What each capability rests on, mirroring the domain: a behaviour cannot be
 * offered without the state it reads or the operation it records. Held here so
 * an impossible set is named on the fieldset rather than coming back as an
 * unattributed 422.
 */
const CAPABILITY_DEPENDENCIES: Partial<Record<ProductCapability, ProductCapability[]>> = {
  SUPPORTS_INTEREST: ['SUPPORTS_BALANCE'],
  SUPPORTS_HOLDINGS: ['SUPPORTS_BALANCE'],
  SUPPORTS_TRADES: ['SUPPORTS_HOLDINGS', 'SUPPORTS_TRANSACTIONS'],
  SUPPORTS_ARBITRAGE: ['SUPPORTS_HOLDINGS', 'SUPPORTS_TRANSACTIONS'],
  SUPPORTS_CONTRIBUTIONS: ['SUPPORTS_TRANSACTIONS'],
  SUPPORTS_FEES: ['SUPPORTS_TRANSACTIONS'],
  SUPPORTS_TAX_TRACKING: ['SUPPORTS_TRANSACTIONS'],
  SUPPORTS_LIABILITY: ['SUPPORTS_BALANCE'],
};

const POSITION_KINDS: AccountKind[] = ['PORTFOLIO', 'INSURANCE_CONTRACT', 'EMPLOYEE_BENEFIT'];

const GROUP_CODE = /^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$/;

export function emptyModelFormValues(): ModelFormValues {
  return {
    name: '',
    family: 'SAVINGS',
    wrapperKind: 'NONE',
    yieldKind: 'NONE',
    defaultGroupCode: '',
    valuationMode: 'TRANSACTIONS',
    capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS'],
  };
}

/** The capability a valuation mode consumes; a model without it cannot feed the mode. */
export function valuationRequiredCapability(mode: AccountValuationMode): ProductCapability {
  switch (mode) {
    case 'TRANSACTIONS':
      return 'SUPPORTS_TRANSACTIONS';
    case 'SNAPSHOTS':
      return 'SUPPORTS_BALANCE';
    case 'PORTFOLIO':
      return 'SUPPORTS_HOLDINGS';
  }
}

export function modeAcceptsFamily(mode: AccountValuationMode, family: AccountKind): boolean {
  return mode !== 'PORTFOLIO' || POSITION_KINDS.includes(family);
}

/**
 * Keeps the capability set consistent with the fields that own two of its
 * members: the valuation mode's capability stays on, and `SUPPORTS_LIABILITY`
 * follows the family. Order is preserved so the checkboxes do not jump.
 */
export function normalizeCapabilities(
  capabilities: ProductCapability[],
  family: AccountKind,
  valuationMode: AccountValuationMode,
): ProductCapability[] {
  const required = valuationRequiredCapability(valuationMode);
  const wanted = new Set(capabilities);
  wanted.add(required);
  if (family === 'LIABILITY') {
    wanted.add('SUPPORTS_LIABILITY');
  } else {
    wanted.delete('SUPPORTS_LIABILITY');
  }

  return CAPABILITIES.filter((capability) => wanted.has(capability));
}

/**
 * The capabilities the current selection needs and does not hold, in checkbox
 * order so the message reads like the list above it.
 *
 * A requirement is followed through: a capability missing here brings its own
 * requirements with it, so the whole set to tick appears at once instead of one
 * per submit.
 */
export function missingCapabilityDependencies(
  capabilities: readonly ProductCapability[],
): ProductCapability[] {
  const held = new Set(capabilities);
  const missing = new Set<ProductCapability>();
  const pending = [...capabilities];

  while (pending.length > 0) {
    const capability = pending.pop() as ProductCapability;
    for (const required of CAPABILITY_DEPENDENCIES[capability] ?? []) {
      if (held.has(required) || missing.has(required)) {
        continue;
      }

      missing.add(required);
      pending.push(required);
    }
  }

  return CAPABILITIES.filter((capability) => missing.has(capability));
}

/**
 * The field keys a submit would send the user back to. `SUPPORTS_LIABILITY` is
 * held exclusive to a `LIABILITY` family, exactly as the domain does, so the
 * two can never disagree about which side of the balance sheet the model sits
 * on.
 */
export function modelFormProblems(values: ModelFormValues): string[] {
  const problems: string[] = [];
  const name = values.name.trim();

  if (name === '' || [...name].length > 80 || /[\p{Cc}\p{Cf}]/u.test(name)) {
    problems.push('name');
  }

  const group = values.defaultGroupCode.trim();
  if (group !== '' && (group.length > 32 || !GROUP_CODE.test(group))) {
    problems.push('defaultGroupCode');
  }

  if (values.capabilities.length === 0) {
    problems.push('capabilities');
  }
  if (!values.capabilities.includes(valuationRequiredCapability(values.valuationMode))) {
    problems.push('capabilities');
  }

  const wantsLiability = values.capabilities.includes('SUPPORTS_LIABILITY');
  if ((values.family === 'LIABILITY') !== wantsLiability) {
    problems.push('capabilities');
  }

  if (missingCapabilityDependencies(values.capabilities).length > 0) {
    problems.push('capabilities');
  }

  if (!modeAcceptsFamily(values.valuationMode, values.family)) {
    problems.push('valuationMode');
  }

  return [...new Set(problems)];
}

/** Unique catalogue group tokens, sorted so the select does not jump between renders. */
export function catalogGroupCodes(products: readonly Product[]): string[] {
  const codes = new Set<string>();
  for (const product of products) {
    if (product.defaultGroupCode !== null) {
      codes.add(product.defaultGroupCode);
    }
  }

  return [...codes].sort();
}

/** Builds the request once {@link modelFormProblems} is empty. Periods are added afterwards. */
export function toCreateRequest(values: ModelFormValues): CreateProductModelRequest {
  const group = values.defaultGroupCode.trim();

  return {
    name: values.name.trim(),
    family: values.family,
    wrapperKind: values.wrapperKind,
    yieldKind: values.yieldKind,
    defaultGroupCode: group === '' ? null : group,
    valuationMode: values.valuationMode,
    capabilities: values.capabilities,
    rules: [],
  };
}
