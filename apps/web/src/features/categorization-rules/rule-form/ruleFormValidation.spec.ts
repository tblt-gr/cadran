import { describe, expect, it } from 'vitest';
import { hasCondition, isDecimal, validateRuleForm } from './ruleFormValidation';

function emptyConditions() {
  return {
    amount: null,
    counterparty: null,
    direction: null,
    mcc: null,
    normalizedLabel: null,
    rawLabel: null,
  } as const;
}

describe('hasCondition', () => {
  it('is false when every condition is empty', () => {
    expect(hasCondition(emptyConditions())).toBe(false);
  });
  it('is true when at least one condition carries a value', () => {
    expect(hasCondition({ ...emptyConditions(), mcc: '5411' })).toBe(true);
  });
});

describe('isDecimal', () => {
  it('accepts a null value', () => {
    expect(isDecimal(null)).toBe(true);
  });
  it('accepts a signed decimal string', () => {
    expect(isDecimal('-12.34')).toBe(true);
  });
  it('rejects a non-numeric string', () => {
    expect(isDecimal('abc')).toBe(false);
  });
});

describe('validateRuleForm', () => {
  const valid = {
    conditions: { ...emptyConditions(), mcc: '5411' },
    effectiveFrom: '2026-01-01',
    effectiveTo: '',
    label: 'Courses',
    priority: '100',
    targetCategoryId: 'category-1',
  };

  it('is valid when every field satisfies its rule', () => {
    const result = validateRuleForm(valid);
    expect(result.isValid).toBe(true);
    expect(result.cleanLabel).toBe('Courses');
    expect(result.parsedPriority).toBe(100);
  });

  it('flags an empty label as invalid', () => {
    expect(validateRuleForm({ ...valid, label: '   ' }).labelInvalid).toBe(true);
  });

  it('flags a priority outside 1-999 as invalid', () => {
    expect(validateRuleForm({ ...valid, priority: '0' }).priorityInvalid).toBe(true);
  });

  it('flags a form with no condition as invalid', () => {
    expect(validateRuleForm({ ...valid, conditions: emptyConditions() }).conditionsInvalid).toBe(
      true,
    );
  });

  it('flags a non-numeric amount bound as invalid', () => {
    const result = validateRuleForm({
      ...valid,
      conditions: {
        ...emptyConditions(),
        amount: { assetCode: 'EUR', max: null, min: 'abc' },
      },
    });
    expect(result.amountInvalid).toBe(true);
  });

  it('flags an end date before the start date as invalid', () => {
    expect(
      validateRuleForm({ ...valid, effectiveFrom: '2026-02-01', effectiveTo: '2026-01-01' })
        .periodInvalid,
    ).toBe(true);
  });

  it('flags a missing target category as invalid', () => {
    expect(validateRuleForm({ ...valid, targetCategoryId: '' }).targetInvalid).toBe(true);
  });
});
