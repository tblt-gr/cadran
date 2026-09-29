import type {
  EmergencyFundEssentialMode,
  EmergencyFundPolicy,
  EmergencyFundReferenceMode,
  UpdateEmergencyFundPolicyRequest,
} from '@cadran/api-client';

export const MAX_ESSENTIAL_CATEGORIES = 50;
export const MAX_TRAILING_MONTHS = 24;
export const MAX_TARGET_MONTHS = 60;

export interface PolicyDraft {
  essentialMode: EmergencyFundEssentialMode;
  categories: EmergencyFundPolicy['essentialCategories'];
  referenceMode: EmergencyFundReferenceMode;
  trailingMonths: string;
  targetMonths: string;
}

export interface PolicyValidity {
  categories: boolean;
  trailingMonths: boolean;
  targetMonths: boolean;
}

export function draftFromPolicy(policy: EmergencyFundPolicy): PolicyDraft {
  return {
    essentialMode: policy.essentialMode,
    categories: policy.essentialCategories,
    referenceMode: policy.referenceMode,
    trailingMonths: policy.trailingMonths === null ? '12' : String(policy.trailingMonths),
    targetMonths: policy.targetMonths,
  };
}

/** Accepts a decimal comma; the wire format always uses a dot. */
export function normalizeTarget(value: string): string {
  return value.trim().replace(',', '.');
}

/** 0 to 60 months with at most one decimal, checked on the digits so no float is involved. */
export function isValidTargetMonths(value: string): boolean {
  const target = normalizeTarget(value);
  if (!/^\d{1,2}(?:\.\d)?$/.test(target)) return false;
  const [whole, fraction = '0'] = target.split('.');
  return Number.parseInt(whole, 10) < MAX_TARGET_MONTHS || (whole === '60' && fraction === '0');
}

export function isValidTrailingMonths(value: string): boolean {
  if (!/^\d{1,2}$/.test(value.trim())) return false;
  const months = Number.parseInt(value, 10);
  return months >= 1 && months <= MAX_TRAILING_MONTHS;
}

export function policyValidity(draft: PolicyDraft): PolicyValidity {
  return {
    categories:
      draft.essentialMode === 'AXIS' ||
      (draft.categories.length >= 1 && draft.categories.length <= MAX_ESSENTIAL_CATEGORIES),
    trailingMonths:
      draft.referenceMode === 'PREVIOUS_YEAR' || isValidTrailingMonths(draft.trailingMonths),
    targetMonths: isValidTargetMonths(draft.targetMonths),
  };
}

export function policyRequest(
  draft: PolicyDraft,
  version: number,
): UpdateEmergencyFundPolicyRequest {
  return {
    essentialMode: draft.essentialMode,
    essentialCategoryIds:
      draft.essentialMode === 'CATEGORIES' ? draft.categories.map((category) => category.id) : [],
    referenceMode: draft.referenceMode,
    trailingMonths:
      draft.referenceMode === 'TRAILING_MONTHS' ? Number.parseInt(draft.trailingMonths, 10) : null,
    targetMonths: normalizeTarget(draft.targetMonths),
    version,
  };
}
