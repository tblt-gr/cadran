import type {
  EmergencyFundEssentialMode,
  EmergencyFundPolicy,
  EmergencyFundReferenceMode,
  EmergencyFundTargetMode,
  UpdateEmergencyFundPolicyRequest,
} from '@cadran/api-client';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import {
  isValidPositiveRatioPercentage,
  percentageToRatio,
  ratioToPercentage,
} from '@/features/goals/goalDecimal';

export const MAX_ESSENTIAL_CATEGORIES = 50;
const MAX_TRAILING_MONTHS = 24;
const MAX_TARGET_MONTHS = 60;
const DEFAULT_TARGET_MONTHS = '6';

export interface PolicyDraft {
  essentialMode: EmergencyFundEssentialMode;
  categories: EmergencyFundPolicy['essentialCategories'];
  referenceMode: EmergencyFundReferenceMode;
  trailingMonths: string;
  targetMonths: string;
  targetMode: EmergencyFundTargetMode;
  targetAmount: string;
  /** The income share as a percentage the way it is typed: 50 for half. */
  targetRatioPercent: string;
}

export interface PolicyValidity {
  categories: boolean;
  trailingMonths: boolean;
  targetMonths: boolean;
  targetAmount: boolean;
  targetRatio: boolean;
}

export function draftFromPolicy(policy: EmergencyFundPolicy): PolicyDraft {
  return {
    essentialMode: policy.essentialMode,
    categories: policy.essentialCategories,
    referenceMode: policy.referenceMode,
    trailingMonths: policy.trailingMonths === null ? '12' : String(policy.trailingMonths),
    targetMonths: policy.targetMonths,
    targetMode: policy.targetMode,
    targetAmount: policy.targetAmount?.value ?? '',
    targetRatioPercent:
      policy.targetRatio === null ? '' : (ratioToPercentage(policy.targetRatio) ?? ''),
  };
}

/** Accepts a decimal comma; the wire format always uses a dot. */
function normalizeTarget(value: string): string {
  return value.trim().replace(',', '.');
}

/** 0 to 60 months with at most one decimal, checked on the digits so no float is involved. */
export function isValidTargetMonths(value: string): boolean {
  const target = normalizeTarget(value);
  if (!/^\d{1,2}(?:\.\d)?$/.test(target)) return false;
  const [whole, fraction = '0'] = target.split('.');
  return Number.parseInt(whole, 10) < MAX_TARGET_MONTHS || (whole === '60' && fraction === '0');
}

/** An income share above 0 and at most 100 %, whose ratio has at most the 6 decimals the API keeps. */
function isValidTargetRatio(percentage: string): boolean {
  const ratio = percentageToRatio(percentage);
  return (
    isValidPositiveRatioPercentage(percentage) &&
    ratio !== null &&
    ratio.length - ratio.indexOf('.') - 1 <= 6
  );
}

/** A strictly positive exact amount with at most 8 decimals, as a goal target. */
function isValidTargetAmount(value: string): boolean {
  return (
    isCanonicalUnsignedDecimal(value) &&
    !/^0(?:\.0+)?$/.test(value) &&
    /^\d+(?:\.\d{1,8})?$/.test(value)
  );
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
    targetMonths: draft.targetMode !== 'MONTHS' || isValidTargetMonths(draft.targetMonths),
    targetAmount: draft.targetMode !== 'AMOUNT' || isValidTargetAmount(draft.targetAmount),
    targetRatio:
      draft.targetMode !== 'INCOME_RATIO' || isValidTargetRatio(draft.targetRatioPercent),
  };
}

export function policyRequest(
  draft: PolicyDraft,
  version: number,
  assetCode: string,
): UpdateEmergencyFundPolicyRequest {
  return {
    essentialMode: draft.essentialMode,
    essentialCategoryIds:
      draft.essentialMode === 'CATEGORIES' ? draft.categories.map((category) => category.id) : [],
    referenceMode: draft.referenceMode,
    trailingMonths:
      draft.referenceMode === 'TRAILING_MONTHS' ? Number.parseInt(draft.trailingMonths, 10) : null,
    // The months stay on record whichever way the target is set, so switching back finds them.
    targetMonths: isValidTargetMonths(draft.targetMonths)
      ? normalizeTarget(draft.targetMonths)
      : DEFAULT_TARGET_MONTHS,
    version,
    targetMode: draft.targetMode,
    targetAmount: draft.targetMode === 'AMOUNT' ? { value: draft.targetAmount, assetCode } : null,
    targetRatio:
      draft.targetMode === 'INCOME_RATIO' ? percentageToRatio(draft.targetRatioPercent) : null,
  };
}
