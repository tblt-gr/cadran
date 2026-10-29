/**
 * Presentation of exact decimals.
 *
 * A financial figure reaches the browser as a canonical decimal string and must
 * never become a JavaScript `Number` on the way to a screen: `0.1 + 0.2` is the
 * short version of why. `Intl.NumberFormat` accepts a string operand and groups
 * the digits it was handed, so these helpers stay exact for the full 24 decimal
 * places the API may carry.
 *
 * Storage and transport stay exact. Display is the only place a figure is
 * rounded: euro amounts and percentages show exactly 2 fraction digits (the
 * currency's own exponent for one that has another, such as JPY), rounded half
 * away from zero on the decimal string, and a negative zero prints as zero.
 * Quantities (`formatDecimal`) and non-currency assets keep every digit they
 * carry, so no quantity is ever invented or dropped.
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

/** Two fraction digits, the display rule for percentages and euro amounts. */
const DISPLAY_FRACTION_DIGITS = 2;

/** What an amount equal to zero shows instead of a figure. */
const EMPTY_AMOUNT = '-';

/** `signDisplay: 'negative'` (ES2023) hides the sign of a value rounded to zero. */
const NO_NEGATIVE_ZERO = { signDisplay: 'negative' } as Intl.NumberFormatOptions;

/**
 * Renders an amount with its currency, rounded half away from zero to the
 * currency's own number of digits (2 for EUR). An asset the platform does not
 * know as a currency, a crypto-asset say, falls back to the exact grouped
 * figure followed by its code, which still names the unit.
 */
export function formatAmount(value: string, assetCode: string, locale: string): string {
  // An exact zero reads as a plain hyphen-minus rather than "0,00 €".
  if (/^[+-]?0*\.?0*$/.test(value) && /\d/.test(value)) return EMPTY_AMOUNT;
  let digits: number;
  try {
    digits = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: assetCode,
    }).resolvedOptions().maximumFractionDigits!;
  } catch {
    return fractionDigits(value) > MAX_INTL_FRACTION_DIGITS
      ? `${value} ${assetCode}`
      : `${formatDecimal(value, locale)} ${assetCode}`;
  }

  return format(
    new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: assetCode,
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
      ...NO_NEGATIVE_ZERO,
    }),
    value,
  );
}

/** Renders a figure already in percent units with 2 rounded fraction digits, without the unit. */
export function formatPercentDecimal(value: string, locale: string): string {
  return format(
    new Intl.NumberFormat(locale, {
      minimumFractionDigits: DISPLAY_FRACTION_DIGITS,
      maximumFractionDigits: DISPLAY_FRACTION_DIGITS,
      ...NO_NEGATIVE_ZERO,
    }),
    value,
  );
}

/**
 * Rounds a canonical decimal half away from zero to `digits` fraction digits
 * on its digits alone, returning a canonical string with exactly that many
 * digits. A value that rounds to zero carries no sign.
 */
export function roundDecimal(value: string, digits: number): string {
  const negative = value.startsWith('-');
  const [whole, fraction = ''] = (negative ? value.slice(1) : value).split('.');
  const kept = BigInt(whole + fraction.slice(0, digits).padEnd(digits, '0'));
  const up = fraction.length > digits && fraction.charCodeAt(digits) >= 53 ? 1n : 0n;
  const rounded = kept + up;
  const text = rounded.toString().padStart(digits + 1, '0');
  const body = digits === 0 ? text : `${text.slice(0, -digits)}.${text.slice(-digits)}`;

  return negative && rounded !== 0n ? `-${body}` : body;
}

/**
 * Rounds a canonical decimal up to the next whole unit on its digits alone ("1056.88" gives
 * "1057", "-1.5" gives "-1"); a whole value is returned without its fraction.
 */
export function ceilDecimal(value: string): string {
  const [whole, fraction = ''] = value.split('.');
  const kept = BigInt(whole);
  const negative = value.startsWith('-');
  const up = !negative && /[1-9]/.test(fraction) ? 1n : 0n;
  const rounded = kept + up;

  return negative && rounded === 0n ? '0' : rounded.toString();
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

/** Weekday, day, month and year of an ISO 8601 calendar day, read at midday UTC like its siblings. */
export function formatCalendarWeekdayDay(value: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
    weekday: 'long',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00Z`));
}

/** Same as `formatCalendarDay` without the year, for lists already scoped to one month. */
export function formatCalendarDayMonth(value: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  }).format(new Date(`${value}T12:00:00Z`));
}

/**
 * Prints an ISO 8601 calendar day as `dd/mm/yyyy` from its parts, so a
 * timezone can never shift it to the day before.
 */
export function formatCalendarNumericDay(value: string): string {
  const [year, month, day] = value.split('-');

  return `${day}/${month}/${year}`;
}

/**
 * Renders the month of an ISO 8601 calendar day, capitalised for a sentence
 * start. The day is read at midday UTC so a viewer's timezone can never shift
 * it into the previous month.
 */
export function formatCalendarMonth(value: string, locale: string): string {
  const label = new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00Z`));

  return label.charAt(0).toLocaleUpperCase(locale) + label.slice(1);
}

const CANONICAL_UNSIGNED_DECIMAL = /^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;
const CANONICAL_DECIMAL = /^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;

interface ScaledInteger {
  coefficient: bigint;
  scale: number;
}

const GEOMETRY_RATIO_RESOLUTION = 1_000_000_000_000n;
const MAX_SAFE_GEOMETRY_INTEGER = 9_007_199_254_740_991n;

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
  if (!CANONICAL_DECIMAL.test(value)) {
    return false;
  }

  return !value.startsWith('-') || !/^0(?:\.0+)?$/.test(value.slice(1));
}

function parseDecimal(value: string): ScaledInteger {
  if (!isCanonicalDecimal(value)) {
    throw new Error('Exact decimal arithmetic expects a canonical decimal');
  }

  const negative = value.startsWith('-');
  const unsigned = negative ? value.slice(1) : value;
  const [integer, fraction = ''] = unsigned.split('.');
  const coefficient = BigInt(integer + fraction);

  return {
    coefficient: negative ? -coefficient : coefficient,
    scale: fraction.length,
  };
}

function align(value: ScaledInteger, scale: number): bigint {
  return value.coefficient * 10n ** BigInt(scale - value.scale);
}

function canonicalFrom(coefficient: bigint, scale: number): string {
  const negative = coefficient < 0n;
  const digits = (negative ? -coefficient : coefficient).toString().padStart(scale + 1, '0');
  const unsigned = scale === 0 ? digits : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;

  return negative && coefficient !== 0n ? `-${unsigned}` : unsigned;
}

export function addDecimals(a: string, b: string): string {
  const left = parseDecimal(a);
  const right = parseDecimal(b);
  const scale = Math.max(left.scale, right.scale);

  return canonicalFrom(align(left, scale) + align(right, scale), scale);
}

export function subtractDecimals(a: string, b: string): string {
  const left = parseDecimal(a);
  const right = parseDecimal(b);
  const scale = Math.max(left.scale, right.scale);

  return canonicalFrom(align(left, scale) - align(right, scale), scale);
}

export function sumDecimals(values: readonly string[]): string {
  return values.reduce(addDecimals, '0');
}

export function negateDecimal(value: string): string {
  const parsed = parseDecimal(value);

  return canonicalFrom(-parsed.coefficient, parsed.scale);
}

export function isZeroDecimal(value: string): boolean {
  return parseDecimal(value).coefficient === 0n;
}

/** An outflow is shown negative whichever sign the source carries; zero keeps no sign. */
export function asOutflow(value: string): string {
  if (isZeroDecimal(value)) return value;
  return value.startsWith('-') ? value : `-${value.replace(/^\+/, '')}`;
}

export function compareDecimals(a: string, b: string): -1 | 0 | 1 {
  const left = parseDecimal(a);
  const right = parseDecimal(b);
  const scale = Math.max(left.scale, right.scale);
  const alignedLeft = align(left, scale);
  const alignedRight = align(right, scale);

  if (alignedLeft === alignedRight) {
    return 0;
  }

  return alignedLeft < alignedRight ? -1 : 1;
}

/**
 * Converts an exact rational decimal to a bounded, dimensionless coordinate.
 *
 * Financial operands remain BigInts until the ratio has been truncated to
 * twelve decimal places. Only that small geometry integer crosses into the
 * SVG/CSS number system; no displayed or stored financial figure does.
 */
export function decimalRatioForGeometry(numerator: string, denominator: string): number {
  const top = parseDecimal(numerator);
  const bottom = parseDecimal(denominator);
  if (bottom.coefficient <= 0n) {
    throw new Error('A geometry ratio expects a positive denominator');
  }

  const scaled =
    (top.coefficient * 10n ** BigInt(bottom.scale) * GEOMETRY_RATIO_RESOLUTION) /
    (bottom.coefficient * 10n ** BigInt(top.scale));
  if (scaled > MAX_SAFE_GEOMETRY_INTEGER || scaled < -MAX_SAFE_GEOMETRY_INTEGER) {
    throw new Error('A geometry ratio must fit the bounded plot range');
  }

  return parseInt(scaled.toString(), 10) / parseInt(GEOMETRY_RATIO_RESOLUTION.toString(), 10);
}

/**
 * Exact share of a part in a positive whole, truncated (never rounded) to `scale`
 * decimal places: `decimalRatio('1', '3', 4)` is `0.3333`. The result is a canonical
 * decimal string for `formatRatioPercentage`, never a `Number`.
 */
export function decimalRatio(numerator: string, denominator: string, scale: number): string {
  const top = parseDecimal(numerator);
  const bottom = parseDecimal(denominator);
  if (bottom.coefficient <= 0n) {
    throw new Error('A ratio expects a positive denominator');
  }

  const scaled =
    (top.coefficient * 10n ** BigInt(bottom.scale + scale)) /
    (bottom.coefficient * 10n ** BigInt(top.scale));

  return canonicalFrom(scaled, scale);
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
