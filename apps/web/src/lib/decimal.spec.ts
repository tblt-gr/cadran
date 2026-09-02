import { describe, expect, it } from 'vitest';
import { formatAmount, formatCalendarDay, formatDecimal } from './decimal';

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

  it('returns a precision beyond the formatter unchanged rather than rounding it', () => {
    const value = '0.123456789012345678901234';

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
