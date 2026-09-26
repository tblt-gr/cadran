import { describe, expect, it } from 'vitest';
import { formatSharePercent } from './formatSharePercent';

describe('formatSharePercent', () => {
  it('shows two digits by default, rounded from a 24-digit string', () => {
    expect(formatSharePercent('40.000000000000000000000000')).toBe('40,00 %');
    expect(formatSharePercent('12.500000000000000000000000')).toBe('12,50 %');
    expect(formatSharePercent('-3.250000000000000000000000')).toBe('\u22123,25 %');
    expect(formatSharePercent('13.333333333333333333333333')).toBe('13,33 %');
  });

  it('rounds half away from zero and never shows a negative zero', () => {
    expect(formatSharePercent('0.995')).toBe('1,00 %');
    expect(formatSharePercent('-0.995')).toBe('\u22121,00 %');
    expect(formatSharePercent('-0.001')).toBe('0,00 %');
  });

  it('honours an explicit scale', () => {
    expect(formatSharePercent('4.2', { fractionDigits: 2 })).toBe('4,20 %');
    expect(formatSharePercent('4.25', { fractionDigits: 0 })).toBe('4 %');
  });
});
