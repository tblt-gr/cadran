import { describe, expect, it } from 'vitest';
import { formatSharePercent } from './formatSharePercent';

describe('formatSharePercent', () => {
  it('drops trailing zeros from a canonical decimal string', () => {
    expect(formatSharePercent('40.000000000000000000000000')).toBe('40 %');
    expect(formatSharePercent('12.500000000000000000000000')).toBe('12,5 %');
    expect(formatSharePercent('-3.250000000000000000000000')).toBe('−3,25 %');
  });

  it('keeps two fraction digits on a backend display percent', () => {
    expect(formatSharePercent('4.20', { fractionDigits: 2 })).toBe('4,20 %');
    expect(formatSharePercent('48.20', { fractionDigits: 2 })).toBe('48,20 %');
    expect(formatSharePercent('40.00', { fractionDigits: 2 })).toBe('40,00 %');
  });
});
