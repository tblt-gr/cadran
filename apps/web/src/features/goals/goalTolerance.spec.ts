import { describe, expect, it } from 'vitest';
import { displayTolerance, toleranceInput, validTolerance } from './goalTolerance';

describe('goal tolerance input', () => {
  it('accepts zero and the exact target bound without losing very large digits', () => {
    expect(validTolerance('0', '0', 'RATE')).toBe(true);
    expect(validTolerance('9007199254740993.01', '9007199254740993.01', 'AMOUNT')).toBe(true);
    expect(validTolerance('9007199254740993.02', '9007199254740993.01', 'AMOUNT')).toBe(false);
    expect(validTolerance('15', '15', 'RATE')).toBe(true);
    expect(validTolerance('15.000001', '15', 'RATE')).toBe(false);
  });
  it.each(['INCOME_SHARE', 'ROLLING_INCOME_SHARE', 'RATE'] as const)(
    'converts points for %s exactly',
    (type) => {
      expect(toleranceInput('1', type)).toBe('0.01');
      expect(displayTolerance('0.01', type)).toBe('1');
      expect(toleranceInput('', type)).toBe('0');
    },
  );
  it('matches the API precision: eight amount decimals or six displayed points decimals', () => {
    expect(validTolerance('0.12345678', '800', 'AMOUNT')).toBe(true);
    expect(validTolerance('0.123456789', '800', 'AMOUNT')).toBe(false);
    expect(validTolerance('0.000001', '15', 'RATE')).toBe(true);
    expect(validTolerance('0.0000001', '15', 'RATE')).toBe(false);
  });
  it('rejects locale separators, signs and malformed input', () => {
    for (const value of ['-1', '1,5', 'NaN', '1e2']) {
      expect(validTolerance(value, '800', 'AMOUNT')).toBe(false);
    }
  });
});
