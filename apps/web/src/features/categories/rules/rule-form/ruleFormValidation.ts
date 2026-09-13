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
  textInvalid: boolean;
}

/** A rule with no condition at all would match every transaction, which is never intended. */
export function hasCondition(conditions: CategorizationRuleConditions): boolean {
  return Boolean(
    conditions.text?.predicates.some((predicate) => predicate.value.trim() !== '') ||
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

function decimalParts(value: string) {
  const negative = value.startsWith('-');
  const [integer = '0', fraction = ''] = (negative ? value.slice(1) : value).split('.');
  return { fraction, integer, negative: negative && !/^0*$/.test(integer + fraction) };
}

/**
 * Orders two canonical decimal strings digit by digit, so a bound comparison
 * never goes through binary floating point. It mirrors the server's range
 * check to point at the field; the server remains the authority.
 */
export function compareDecimals(left: string, right: string): -1 | 0 | 1 {
  const a = decimalParts(left);
  const b = decimalParts(right);
  if (a.negative !== b.negative) {
    return a.negative ? -1 : 1;
  }
  const integerWidth = Math.max(a.integer.length, b.integer.length);
  const fractionWidth = Math.max(a.fraction.length, b.fraction.length);
  const digits = (parts: ReturnType<typeof decimalParts>) =>
    parts.integer.padStart(integerWidth, '0') + parts.fraction.padEnd(fractionWidth, '0');
  const magnitude = digits(a) === digits(b) ? 0 : digits(a) < digits(b) ? -1 : 1;
  return a.negative && magnitude !== 0 ? (-magnitude as -1 | 1) : magnitude;
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
  const textInvalid = Boolean(
    conditions.text &&
    (conditions.text.predicates.length > 20 ||
      conditions.text.predicates.some(
        (predicate) => predicate.value.trim() === '' || predicate.value.length > 120,
      )),
  );
  const amount = conditions.amount;
  const amountInvalid = Boolean(
    amount &&
    (!isDecimal(amount.min) ||
      !isDecimal(amount.max) ||
      amount.assetCode === '' ||
      (amount.min === null && amount.max === null) ||
      (amount.min !== null && amount.max !== null && compareDecimals(amount.min, amount.max) > 0)),
  );
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
      textInvalid ||
      amountInvalid ||
      periodInvalid
    ),
    labelInvalid,
    parsedPriority,
    periodInvalid,
    priorityInvalid,
    targetInvalid,
    textInvalid,
  };
}
