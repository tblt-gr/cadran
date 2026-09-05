/**
 * Presentation of exact decimals.
 *
 * A financial figure reaches the browser as a canonical decimal string and must
 * never become a JavaScript `Number` on the way to a screen: `0.1 + 0.2` is the
 * short version of why. `Intl.NumberFormat` accepts a string operand and groups
 * the digits it was handed, so these helpers stay exact for the full 24 decimal
 * places the API may carry.
 *
 * They also never round. The fraction digits shown are the ones the value
 * carries, so a reference figure is displayed as recorded and no digit is
 * invented or dropped at the boundary.
 */

/** ECMA-402 NumberFormat v3 accepts up to 100 fraction digits. */
const MAX_INTL_FRACTION_DIGITS = 100;

/**
 * ECMA-402 lets `format` take a string operand and read its digits directly.
 * TypeScript still types the parameter as a template-literal numeric type that
 * a value built at runtime cannot satisfy, so the narrowing lives here, once,
 * rather than at each call site. The string is never converted to a number.
 */
function format(formatter: Intl.NumberFormat, value: string): string {
  return formatter.format(value as unknown as number);
}

function fractionDigits(value: string): number {
  const separator = value.indexOf('.');

  return separator === -1 ? 0 : value.length - separator - 1;
}

/**
 * Groups a canonical decimal for reading, keeping every digit it carries.
 * Returns the value unchanged when its precision is beyond what the platform
 * formatter accepts, because dropping digits would be a rounding decision.
 */
export function formatDecimal(value: string, locale: string): string {
  const digits = fractionDigits(value);
  if (digits > MAX_INTL_FRACTION_DIGITS) {
    return value;
  }

  return format(
    new Intl.NumberFormat(locale, {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }),
    value,
  );
}

/**
 * Renders an amount with its currency. An asset the platform does not know as a
 * currency — a crypto-asset, say — falls back to the grouped figure followed by
 * its code, which is exact and still names the unit.
 */
export function formatAmount(value: string, assetCode: string, locale: string): string {
  const digits = fractionDigits(value);
  if (digits > MAX_INTL_FRACTION_DIGITS) {
    return `${value} ${assetCode}`;
  }

  try {
    return format(
      new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: assetCode,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
      }),
      value,
    );
  } catch {
    return `${formatDecimal(value, locale)} ${assetCode}`;
  }
}

/**
 * Renders an ISO 8601 calendar day. The day is read at midday UTC so a viewer's
 * timezone can never shift it to the day before.
 */
export function formatCalendarDay(value: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00Z`));
}

const CANONICAL_UNSIGNED_DECIMAL = /^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;
const CANONICAL_DECIMAL = /^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;

/** A decimal string with no sign, no leading zero and no locale formatting. */
export function isCanonicalUnsignedDecimal(value: string): boolean {
  return CANONICAL_UNSIGNED_DECIMAL.test(value);
}

/**
 * A recorded figure as the API stores it: optional minus, no leading zero,
 * no locale grouping. A signed form is required because an overdraft is a
 * real balance, not a formatting choice.
 */
export function isCanonicalDecimal(value: string): boolean {
  return CANONICAL_DECIMAL.test(value);
}

/**
 * Orders two canonical unsigned decimals (`123`, `0`, `4.50`) without ever
 * reading them as a `Number`. Digits alone decide the order: the integer part
 * compares by length then lexicographically, since a canonical integer part
 * never carries a leading zero, and the fraction part compares lexicographically
 * once padded to the same length.
 *
 * Both operands must already be canonical unsigned decimals — the length-first
 * comparison this relies on reads a sign or a leading zero as extra integer
 * digits and answers a wrong order silently, so an uncanonical operand throws
 * rather than being trusted.
 */
export function compareUnsignedDecimals(a: string, b: string): number {
  if (!isCanonicalUnsignedDecimal(a) || !isCanonicalUnsignedDecimal(b)) {
    throw new Error('compareUnsignedDecimals expects two canonical unsigned decimals');
  }

  const [integerA, fractionA = ''] = a.split('.');
  const [integerB, fractionB = ''] = b.split('.');

  if (integerA.length !== integerB.length) {
    return integerA.length - integerB.length;
  }
  if (integerA !== integerB) {
    return integerA < integerB ? -1 : 1;
  }

  const width = Math.max(fractionA.length, fractionB.length);
  const paddedA = fractionA.padEnd(width, '0');
  const paddedB = fractionB.padEnd(width, '0');

  if (paddedA === paddedB) {
    return 0;
  }

  return paddedA < paddedB ? -1 : 1;
}
