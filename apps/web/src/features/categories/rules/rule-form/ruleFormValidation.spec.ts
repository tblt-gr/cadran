import { describe, expect, it } from 'vitest';
import { compareDecimals, hasCondition, isDecimal, validateRuleForm } from './ruleFormValidation';

function emptyConditions() {
  return {
    amount: null,
    direction: null,
    mcc: null,
    text: null,
  } as const;
}

describe('hasCondition', () => {
  it('is false when every condition is empty', () => {
    expect(hasCondition(emptyConditions())).toBe(false);
  });
  it('is true when at least one condition carries a value', () => {
    expect(hasCondition({ ...emptyConditions(), mcc: '5411' })).toBe(true);
  });

  it('is true for a completed text predicate and false for an empty one', () => {
    expect(
      hasCondition({
        ...emptyConditions(),
        text: {
          combinator: 'AND',
          predicates: [
            { source: 'COUNTERPARTY', operator: 'CONTAINS', value: 'Carrefour', negated: false },
          ],
        },
      }),
    ).toBe(true);
    expect(
      hasCondition({
        ...emptyConditions(),
        text: {
          combinator: 'AND',
          predicates: [
            { source: 'COUNTERPARTY', operator: 'CONTAINS', value: '   ', negated: false },
          ],
        },
      }),
    ).toBe(false);
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

describe('compareDecimals', () => {
  it.each([
    ['-250.00', '-5.00', -1],
    ['10', '9.99', 1],
    ['1.50', '1.5', 0],
    ['-0.00', '0', 0],
    ['-1', '0.5', -1],
    ['123456789012345678901234.000000000000000000000001', '123456789012345678901234', 1],
  ] as const)('compares %s with %s', (left, right, expected) => {
    expect(compareDecimals(left, right)).toBe(expected);
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

  it('flags an incomplete or overlong text predicate', () => {
    const incomplete = validateRuleForm({
      ...valid,
      conditions: {
        ...emptyConditions(),
        text: {
          combinator: 'OR',
          predicates: [{ source: 'RAW_LABEL', operator: 'CONTAINS', value: '', negated: false }],
        },
      },
    });
    const overlong = validateRuleForm({
      ...valid,
      conditions: {
        ...emptyConditions(),
        text: {
          combinator: 'AND',
          predicates: [
            { source: 'RAW_LABEL', operator: 'CONTAINS', value: 'a'.repeat(121), negated: false },
          ],
        },
      },
    });

    expect(incomplete.textInvalid).toBe(true);
    expect(overlong.textInvalid).toBe(true);
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

  it('flags an amount condition without any bound or with a minimum above its maximum', () => {
    const amountInvalid = (min: string | null, max: string | null) =>
      validateRuleForm({
        ...valid,
        conditions: { ...valid.conditions, amount: { assetCode: 'EUR', max, min } },
      }).amountInvalid;

    expect(amountInvalid(null, null)).toBe(true);
    expect(amountInvalid('-5.00', '-250.00')).toBe(true);
    expect(amountInvalid('-250.00', '-5.00')).toBe(false);
    expect(amountInvalid('1.50', '1.5')).toBe(false);
    expect(amountInvalid(null, '-5.00')).toBe(false);
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
