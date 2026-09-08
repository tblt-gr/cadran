import { describe, expect, it } from 'vitest';
import referenceCases from '@fixtures/decimal-reference-cases.json';
import {
  addDecimals,
  compareDecimals,
  compareUnsignedDecimals,
  formatAmount,
  formatCalendarDay,
  formatCalendarNumericDay,
  formatDecimal,
  isCanonicalDecimal,
  isCanonicalUnsignedDecimal,
  isZeroDecimal,
  negateDecimal,
  subtractDecimals,
  sumDecimals,
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

describe('formatCalendarNumericDay', () => {
  it('prints an ISO calendar day as dd/mm/yyyy without shifting the date', () => {
    expect(formatCalendarNumericDay('2026-08-01')).toBe('01/08/2026');
    expect(formatCalendarNumericDay('2026-09-05')).toBe('05/09/2026');
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

describe('isCanonicalDecimal', () => {
  it('accepts a signed or unsigned canonical figure', () => {
    expect(isCanonicalDecimal('0')).toBe(true);
    expect(isCanonicalDecimal('230.5688')).toBe(true);
    expect(isCanonicalDecimal('-50.20')).toBe(true);
  });

  it('refuses a leading zero, a comma or an empty string', () => {
    expect(isCanonicalDecimal('007')).toBe(false);
    expect(isCanonicalDecimal('4,5')).toBe(false);
    expect(isCanonicalDecimal('')).toBe(false);
    expect(isCanonicalDecimal('-0')).toBe(false);
    expect(isCanonicalDecimal('-0.00')).toBe(false);
  });
});

describe('signed exact decimal arithmetic', () => {
  it('matches every shared reference case without changing scale', () => {
    for (const testCase of referenceCases.add) {
      expect(addDecimals(testCase.a, testCase.b)).toBe(testCase.result);
    }
    for (const testCase of referenceCases.subtract) {
      expect(subtractDecimals(testCase.a, testCase.b)).toBe(testCase.result);
    }
    for (const testCase of referenceCases.sum) {
      expect(sumDecimals(testCase.values)).toBe(testCase.result);
    }
    for (const testCase of referenceCases.compare) {
      expect(compareDecimals(testCase.a, testCase.b)).toBe(testCase.result);
    }
    for (const rejected of referenceCases.rejected) {
      expect(() => addDecimals(rejected, '1')).toThrow();
      expect(() => compareDecimals('1', rejected)).toThrow();
    }
  });

  it('keeps zero unsigned while retaining its submitted scale', () => {
    expect(negateDecimal('0')).toBe('0');
    expect(negateDecimal('0.00')).toBe('0.00');
    expect(subtractDecimals('1.50', '1.50')).toBe('0.00');
    expect(isZeroDecimal('0.000')).toBe(true);
    expect(isZeroDecimal('-0.001')).toBe(false);
  });

  it('obeys inverse, sum and ordering properties over generated canonical operands', () => {
    let seed = 1_234_567;
    const next = () => {
      seed = (seed * 48_271) % 2_147_483_647;
      return seed;
    };
    const operand = () => {
      const integer = String(next() % 1_000_000);
      const scale = next() % 9;
      let fraction = '';
      for (let index = 0; index < scale; index += 1) fraction += String(next() % 10);
      const unsigned = `${integer}${fraction === '' ? '' : `.${fraction}`}`;
      return !isZeroDecimal(unsigned) && next() % 2 === 0 ? `-${unsigned}` : unsigned;
    };

    for (let attempt = 0; attempt < 250; attempt += 1) {
      const [a, b, c] = [operand(), operand(), operand()];
      expect(compareDecimals(subtractDecimals(addDecimals(a, b), b), a)).toBe(0);
      expect(negateDecimal(negateDecimal(a))).toBe(a);
      expect(sumDecimals([a, b, c])).toBe(addDecimals(addDecimals(a, b), c));

      const comparison = compareDecimals(a, b);
      expect([-1, 0, 1]).toContain(comparison);
      expect(compareDecimals(b, a)).toBe(comparison === 0 ? 0 : -comparison);
      expect(compareDecimals(a, a)).toBe(0);
    }
  });
});
