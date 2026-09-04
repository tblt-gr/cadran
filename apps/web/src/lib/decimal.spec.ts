import { describe, expect, it } from 'vitest';
import {
  compareUnsignedDecimals,
  formatAmount,
  formatCalendarDay,
  formatDecimal,
  isCanonicalUnsignedDecimal,
} from './decimal';

const narrowNoBreakSpace = / | |\s/g;

function normalize(value: string) {
  return value.replace(narrowNoBreakSpace, ' ');
}

describe('formatDecimal', () => {
  it('groups a figure while keeping the digits it carries', () => {
    expect(normalize(formatDecimal('22950', 'fr-FR'))).toBe('22 950');
    expect(normalize(formatDecimal('1.7', 'fr-FR'))).toBe('1,7');
  });

  it('keeps a precision no binary float could hold', () => {
    // 0.1 + 0.2 as a JavaScript number is 0.30000000000000004. The string
    // operand never becomes a number, so the digits arrive intact.
    expect(normalize(formatDecimal('1234567890123456789.123456789', 'fr-FR'))).toBe(
      '1 234 567 890 123 456 789,123456789',
    );
  });

  it('groups all 24 fractional digits supported by the financial storage', () => {
    const value = '0.123456789012345678901234';

    expect(formatDecimal(value, 'fr-FR')).toBe('0,123456789012345678901234');
  });

  it('returns a precision beyond NumberFormat v3 unchanged rather than rounding it', () => {
    const value = `0.${'1'.repeat(101)}`;

    expect(formatDecimal(value, 'fr-FR')).toBe(value);
  });
});

describe('formatAmount', () => {
  it('renders a currency amount at the precision it was given', () => {
    expect(normalize(formatAmount('22950', 'EUR', 'fr-FR'))).toBe('22 950 €');
    expect(normalize(formatAmount('22950.50', 'EUR', 'fr-FR'))).toBe('22 950,50 €');
  });

  it('names the unit of an asset the platform does not know as a currency', () => {
    expect(normalize(formatAmount('1.5', 'XBTTEST', 'fr-FR'))).toBe('1,5 XBTTEST');
  });
});

describe('formatCalendarDay', () => {
  it('reads a day in UTC so no timezone shifts it', () => {
    expect(normalize(formatCalendarDay('2026-08-01', 'fr-FR'))).toBe('1 août 2026');
  });
});

describe('compareUnsignedDecimals', () => {
  it('orders by digit count before digit value, never as a float', () => {
    expect(compareUnsignedDecimals('9', '10')).toBeLessThan(0);
    expect(compareUnsignedDecimals('10000', '9999')).toBeGreaterThan(0);
  });

  it('treats equal magnitudes at different scales as equal', () => {
    expect(compareUnsignedDecimals('4.50', '4.5')).toBe(0);
    expect(compareUnsignedDecimals('0', '0.00')).toBe(0);
  });

  it('compares fraction digits once padded to the same width', () => {
    expect(compareUnsignedDecimals('1.09', '1.1')).toBeLessThan(0);
    expect(compareUnsignedDecimals('1.10000000000000000000001', '1.1')).toBeGreaterThan(0);
  });

  it('throws on a signed or non-canonical operand rather than answering a wrong order', () => {
    // '-1' would otherwise read as a 2-digit integer part longer than '5'.
    expect(() => compareUnsignedDecimals('-1', '5')).toThrow();
    expect(() => compareUnsignedDecimals('007', '8')).toThrow();
    expect(() => compareUnsignedDecimals('4,5', '4.5')).toThrow();
  });
});

describe('isCanonicalUnsignedDecimal', () => {
  it('accepts an unsigned integer or decimal with no leading zero', () => {
    expect(isCanonicalUnsignedDecimal('0')).toBe(true);
    expect(isCanonicalUnsignedDecimal('4.50')).toBe(true);
  });

  it('refuses a sign, a leading zero, a comma or an empty string', () => {
    expect(isCanonicalUnsignedDecimal('-1')).toBe(false);
    expect(isCanonicalUnsignedDecimal('007')).toBe(false);
    expect(isCanonicalUnsignedDecimal('4,5')).toBe(false);
    expect(isCanonicalUnsignedDecimal('')).toBe(false);
  });
});
