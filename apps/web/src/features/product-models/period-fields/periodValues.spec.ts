import { describe, expect, it } from 'vitest';
import { emptyPeriod, periodProblems, toRuleInput, type PeriodValues } from './periodValues';

function ratePeriod(overrides: Partial<PeriodValues> = {}): PeriodValues {
  return {
    ...emptyPeriod('ANNUAL_RATE'),
    validFrom: '2026-01-01',
    ...overrides,
  };
}

describe('periodValues', () => {
  it('sends a single rate as one open-ended bracket of canonical strings', () => {
    const values = ratePeriod({ scaleShape: 'single', singleRate: '4' });

    expect(periodProblems(values)).toEqual([]);
    expect(toRuleInput(values)).toEqual({
      kind: 'ANNUAL_RATE',
      amount: null,
      amountAssetCode: null,
      text: null,
      rateApplication: 'MARGINAL',
      brackets: [{ lowerBound: '0', upperBound: null, percentage: '4' }],
      validFrom: '2026-01-01',
      validTo: null,
    });
  });

  it('keeps a tiered scale as strings, including an explicit application mode', () => {
    const values = ratePeriod({
      scaleShape: 'tiered',
      rateApplication: 'FLAT_BY_BRACKET',
      brackets: [
        { lowerBound: '0', upperBound: '10000', percentage: '4' },
        { lowerBound: '10000', upperBound: '', percentage: '2' },
      ],
    });

    expect(periodProblems(values)).toEqual([]);
    expect(toRuleInput(values).brackets).toEqual([
      { lowerBound: '0', upperBound: '10000', percentage: '4' },
      { lowerBound: '10000', upperBound: null, percentage: '2' },
    ]);
    expect(toRuleInput(values).rateApplication).toBe('FLAT_BY_BRACKET');
  });

  it('refuses a locale-formatted or padded rate rather than parsing it', () => {
    expect(periodProblems(ratePeriod({ singleRate: '4,5' }))).toContain('brackets');
    expect(periodProblems(ratePeriod({ singleRate: '04' }))).toContain('brackets');
    expect(periodProblems(ratePeriod({ singleRate: '+4' }))).toContain('brackets');
    expect(periodProblems(ratePeriod({ singleRate: '4.50' }))).toEqual([]);
  });

  it('refuses a negative ceiling, which is a bound and never a debt', () => {
    const ceiling = {
      ...emptyPeriod('DEPOSIT_CEILING'),
      validFrom: '2026-01-01',
      amount: '-100',
    };

    expect(periodProblems(ceiling)).toContain('amount');
    expect(periodProblems({ ...ceiling, amount: '100' })).toEqual([]);
  });

  it('treats an empty end date as in force with no known end, never as a zero', () => {
    const values = ratePeriod({ singleRate: '1.7', validTo: '' });

    expect(periodProblems(values)).toEqual([]);
    expect(toRuleInput(values).validTo).toBeNull();
  });
});
