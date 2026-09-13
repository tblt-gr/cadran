import type { CategorizationRuleConditions } from '@cadran/api-client';

export interface RuleFormValidationInput {
  conditions: CategorizationRuleConditions;
  effectiveFrom: string;
  effectiveTo: string;
  label: string;
  priority: string;
  targetCategoryId: string;
}

export interface RuleFormValidation {
  amountInvalid: boolean;
  cleanLabel: string;
  conditionsInvalid: boolean;
  isValid: boolean;
  labelInvalid: boolean;
  parsedPriority: number;
  periodInvalid: boolean;
  priorityInvalid: boolean;
  targetInvalid: boolean;
}

/** A rule with no condition at all would match every transaction, which is never intended. */
export function hasCondition(conditions: CategorizationRuleConditions): boolean {
  return Boolean(
    conditions.rawLabel?.value ||
    conditions.normalizedLabel?.value ||
    conditions.counterparty?.value ||
    conditions.mcc ||
    conditions.direction ||
    conditions.amount?.min ||
    conditions.amount?.max,
  );
}

/** An empty bound is left unconstrained; a filled one must parse as an exact decimal. */
export function isDecimal(value: string | null): boolean {
  return value === null || /^-?(0|[1-9]\d*)(\.\d+)?$/.test(value);
}

/**
 * The rule form's field-level validity, computed together because several
 * checks (the exact amount range, the effective period) compare more than
 * one field.
 */
export function validateRuleForm({
  conditions,
  effectiveFrom,
  effectiveTo,
  label,
  priority,
  targetCategoryId,
}: RuleFormValidationInput): RuleFormValidation {
  const cleanLabel = label.trim();
  const parsedPriority = Number.parseInt(priority, 10);
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const priorityInvalid = !/^\d{1,3}$/.test(priority) || parsedPriority < 1 || parsedPriority > 999;
  const conditionsInvalid = !hasCondition(conditions);
  const amountInvalid =
    conditions.amount !== null &&
    conditions.amount !== undefined &&
    (!isDecimal(conditions.amount.min) ||
      !isDecimal(conditions.amount.max) ||
      conditions.amount.assetCode === '');
  const periodInvalid = effectiveFrom === '' || (effectiveTo !== '' && effectiveTo < effectiveFrom);
  const targetInvalid = targetCategoryId === '';

  return {
    amountInvalid,
    cleanLabel,
    conditionsInvalid,
    isValid: !(
      labelInvalid ||
      priorityInvalid ||
      targetInvalid ||
      conditionsInvalid ||
      amountInvalid ||
      periodInvalid
    ),
    labelInvalid,
    parsedPriority,
    periodInvalid,
    priorityInvalid,
    targetInvalid,
  };
}
