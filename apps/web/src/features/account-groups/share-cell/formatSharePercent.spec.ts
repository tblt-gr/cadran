import { describe, expect, it } from 'vitest';
import { formatSharePercent } from './formatSharePercent';

describe('formatSharePercent', () => {
  it('drops trailing zeros from a canonical decimal string', () => {
    expect(formatSharePercent('40.000000000000000000000000')).toBe('40 %');
    expect(formatSharePercent('12.500000000000000000000000')).toBe('12,5 %');
    expect(formatSharePercent('-3.250000000000000000000000')).toBe('−3,25 %');
  });
});
