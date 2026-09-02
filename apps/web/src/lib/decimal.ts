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

/** `Intl.NumberFormat` refuses a fraction precision beyond this in some engines. */
const MAX_INTL_FRACTION_DIGITS = 20;

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
