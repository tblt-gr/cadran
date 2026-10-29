import { describe, expect, it } from 'vitest';
import {
  isValidTargetMonths,
  isValidTrailingMonths,
  policyRequest,
  policyValidity,
  type PolicyDraft,
} from './emergencyFundPolicyFields';

const draft: PolicyDraft = {
  essentialMode: 'AXIS',
  categories: [{ id: 'c1', label: 'Loyer', archived: false }],
  referenceMode: 'PREVIOUS_YEAR',
  trailingMonths: '12',
  targetMonths: '6',
  targetMode: 'MONTHS',
  targetAmount: '',
  targetRatioPercent: '',
};

describe('emergency fund policy fields', () => {
  it.each([
    ['0', true],
    ['6', true],
    ['4,5', true],
    ['60', true],
    ['60.0', true],
    ['60.1', false],
    ['61', false],
    ['1.25', false],
    ['-1', false],
    ['', false],
    ['1e1', false],
  ])('target %s valid is %s', (value, valid) => {
    expect(isValidTargetMonths(value)).toBe(valid);
  });

  it.each([
    ['1', true],
    ['24', true],
    ['0', false],
    ['25', false],
    ['1.5', false],
  ])('trailing months %s valid is %s', (value, valid) => {
    expect(isValidTrailingMonths(value)).toBe(valid);
  });

  it('requires 1 to 50 categories only in CATEGORIES mode', () => {
    expect(policyValidity({ ...draft, categories: [] }).categories).toBe(true);
    expect(
      policyValidity({ ...draft, essentialMode: 'CATEGORIES', categories: [] }).categories,
    ).toBe(false);
    const fifty = Array.from({ length: 51 }, (_, index) => ({
      id: `c${index}`,
      label: 'x',
      archived: false,
    }));
    expect(
      policyValidity({ ...draft, essentialMode: 'CATEGORIES', categories: fifty }).categories,
    ).toBe(false);
  });

  it('sends no categories in AXIS mode and no months for the previous year', () => {
    expect(policyRequest({ ...draft, targetMonths: '4,5' }, 3, 'EUR')).toEqual({
      essentialMode: 'AXIS',
      essentialCategoryIds: [],
      referenceMode: 'PREVIOUS_YEAR',
      trailingMonths: null,
      targetMonths: '4.5',
      version: 3,
      targetMode: 'MONTHS',
      targetAmount: null,
      targetRatio: null,
    });
  });

  it('sends the chosen categories and months in the explicit modes', () => {
    expect(
      policyRequest(
        {
          ...draft,
          essentialMode: 'CATEGORIES',
          referenceMode: 'TRAILING_MONTHS',
          trailingMonths: '9',
        },
        0,
        'EUR',
      ),
    ).toMatchObject({ essentialCategoryIds: ['c1'], trailingMonths: 9, version: 0 });
  });

  it('sends a fixed amount in the workspace currency and drops the other targets', () => {
    expect(
      policyRequest({ ...draft, targetMode: 'AMOUNT', targetAmount: '15000' }, 1, 'EUR'),
    ).toMatchObject({
      targetMode: 'AMOUNT',
      targetAmount: { value: '15000', assetCode: 'EUR' },
      targetRatio: null,
    });
  });

  it('sends an income share as an exact ratio, by moving digits only', () => {
    expect(
      policyRequest({ ...draft, targetMode: 'INCOME_RATIO', targetRatioPercent: '37.5' }, 1, 'EUR'),
    ).toMatchObject({ targetMode: 'INCOME_RATIO', targetAmount: null, targetRatio: '0.375' });
  });

  it.each([
    ['AMOUNT', { targetAmount: '0' }, 'targetAmount', false],
    ['AMOUNT', { targetAmount: '1200.5' }, 'targetAmount', true],
    ['AMOUNT', { targetAmount: '1,5' }, 'targetAmount', false],
    ['INCOME_RATIO', { targetRatioPercent: '50' }, 'targetRatio', true],
    ['INCOME_RATIO', { targetRatioPercent: '100' }, 'targetRatio', true],
    ['INCOME_RATIO', { targetRatioPercent: '100.1' }, 'targetRatio', false],
    ['INCOME_RATIO', { targetRatioPercent: '0' }, 'targetRatio', false],
    ['INCOME_RATIO', { targetRatioPercent: '12.34567' }, 'targetRatio', false],
  ] as const)('validates the %s target %j', (mode, patch, field, valid) => {
    expect(policyValidity({ ...draft, targetMode: mode, ...patch })[field]).toBe(valid);
  });

  it('does not require the months when another target is chosen', () => {
    expect(
      policyValidity({ ...draft, targetMode: 'AMOUNT', targetAmount: '10', targetMonths: '' })
        .targetMonths,
    ).toBe(true);
  });
});
