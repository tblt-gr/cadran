import { describe, expect, it } from 'vitest';
import boundContract from '@contracts/rate-scale-bounds.json';
import {
  emptyPeriod,
  matchingBracket,
  periodProblems,
  toRuleInput,
  type BracketValues,
  type PeriodValues,
} from './periodValues';

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

describe('matchingBracket', () => {
  const scale = [
    { lowerBound: '0', upperBound: '10000', percentage: '4' },
    { lowerBound: '10000', upperBound: '', percentage: '2' },
  ];

  it('finds the bracket an example balance falls into, bound included at the lower edge', () => {
    expect(matchingBracket(scale, '0')).toEqual({ kind: 'match', bracket: scale[0] });
    expect(matchingBracket(scale, '9999.99')).toEqual({ kind: 'match', bracket: scale[0] });
    expect(matchingBracket(scale, '10000')).toEqual({ kind: 'match', bracket: scale[1] });
  });

  it('reaches the open-ended last bracket for a balance far beyond any upper bound', () => {
    expect(matchingBracket(scale, '1000000000000000000000000')).toEqual({
      kind: 'match',
      bracket: scale[1],
    });
  });

  it('is unmoved by a bound carrying stray whitespace', () => {
    const padded = [
      { lowerBound: '0 ', upperBound: ' 10000', percentage: ' 4' },
      { lowerBound: '10000 ', upperBound: '', percentage: '2' },
    ];

    expect(matchingBracket(padded, ' 15000 ')).toEqual({
      kind: 'match',
      bracket: { lowerBound: '10000', upperBound: '', percentage: '2' },
    });
  });

  it('answers invalidBalance for a blank or malformed balance, not for a sign or a pad', () => {
    expect(matchingBracket(scale, '')).toEqual({ kind: 'invalidBalance' });
    expect(matchingBracket(scale, '4,50')).toEqual({ kind: 'invalidBalance' });
  });

  it('names a negative, padded or mid-typed balance instead of blaming the digits-and-dot rule', () => {
    expect(matchingBracket(scale, '-500')).toEqual({ kind: 'negativeBalance' });
    expect(matchingBracket(scale, '007')).toEqual({ kind: 'paddedBalance' });
    expect(matchingBracket(scale, '1000.')).toEqual({ kind: 'incompleteBalance' });
    expect(matchingBracket(scale, '0')).toEqual({ kind: 'match', bracket: scale[0] });
    expect(matchingBracket(scale, '0.7')).toEqual({ kind: 'match', bracket: scale[0] });
  });

  it('answers incompleteScale while a bound in the draft scale is not canonical yet', () => {
    expect(matchingBracket([{ lowerBound: '', upperBound: '', percentage: '4' }], '100')).toEqual({
      kind: 'incompleteScale',
    });
    expect(
      matchingBracket([{ lowerBound: '0', upperBound: '10 000', percentage: '4' }], '100'),
    ).toEqual({ kind: 'incompleteScale' });
  });

  it('answers missingRate, not incompleteScale, when only the reached bracket has no usable rate', () => {
    expect(
      matchingBracket([{ lowerBound: '0', upperBound: '', percentage: '4,5' }], '100'),
    ).toEqual({ kind: 'missingRate' });
    expect(matchingBracket([{ lowerBound: '0', upperBound: '', percentage: '' }], '100')).toEqual({
      kind: 'missingRate',
    });
  });

  it('names the bracket a balance reaches even while a rate above it is still blank', () => {
    // The rate column is filled in row by row: bounds that already tile
    // [0, +∞[ are not made wrong by a tier the holder has not reached yet.
    const halfRated = [
      { lowerBound: '0', upperBound: '10000', percentage: '4' },
      { lowerBound: '10000', upperBound: '', percentage: '' },
    ];

    expect(matchingBracket(halfRated, '5000')).toEqual({ kind: 'match', bracket: halfRated[0] });
    expect(matchingBracket(halfRated, '15000')).toEqual({ kind: 'missingRate' });
  });

  it('answers incompleteScale for a scale that does not start at zero or does not end open-ended', () => {
    expect(
      matchingBracket([{ lowerBound: '100', upperBound: '', percentage: '4' }], '100'),
    ).toEqual({ kind: 'incompleteScale' });
    expect(
      matchingBracket([{ lowerBound: '0', upperBound: '10000', percentage: '4' }], '5000'),
    ).toEqual({ kind: 'incompleteScale' });
  });

  it('answers incompleteScale for a scale with a gap or an overlap between brackets', () => {
    const gap = [
      { lowerBound: '0', upperBound: '10000', percentage: '4' },
      { lowerBound: '20000', upperBound: '', percentage: '2' },
    ];
    const overlap = [
      { lowerBound: '0', upperBound: '10000', percentage: '4' },
      { lowerBound: '5000', upperBound: '', percentage: '2' },
    ];

    expect(matchingBracket(gap, '15000')).toEqual({ kind: 'incompleteScale' });
    expect(matchingBracket(overlap, '7000')).toEqual({ kind: 'incompleteScale' });
  });

  it('answers incompleteScale for rows out of order, since row order is what the backend reads', () => {
    // Well-formed once sorted by lower bound, but `toRuleInput` and
    // `RateScale::__construct` both read row order, not sorted bounds — a
    // scale that would only pass once reordered does not pass.
    const reversed = [
      { lowerBound: '10000', upperBound: '', percentage: '2' },
      { lowerBound: '0', upperBound: '10000', percentage: '4' },
    ];

    expect(matchingBracket(reversed, '15000')).toEqual({ kind: 'incompleteScale' });
  });

  it.each(boundContract.cases)(
    'reads the shared bound contract the same way as RateScale: $name',
    ({ brackets, wellBounded }) => {
      const draft: BracketValues[] = brackets.map((bracket) => ({
        lowerBound: bracket.lowerBound,
        upperBound: bracket.upperBound ?? '',
        percentage: bracket.percentage,
      }));

      expect(matchingBracket(draft, '0').kind).toBe(wellBounded ? 'match' : 'incompleteScale');
    },
  );
});
