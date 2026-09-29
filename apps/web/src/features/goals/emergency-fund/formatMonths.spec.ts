import { describe, expect, it } from 'vitest';
import { formatMonths } from './formatMonths';

describe('formatMonths', () => {
  it.each([
    ['6.0', '6'],
    ['4.9', '4,9'],
    ['0.0', '0'],
    ['60', '60'],
  ])('formats %s as %s', (value, expected) => {
    expect(formatMonths(value, 'fr-FR')).toBe(expected);
  });
});
