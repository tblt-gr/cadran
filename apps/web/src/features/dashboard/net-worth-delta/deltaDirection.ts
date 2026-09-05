/** A canonical decimal that is exactly zero, whatever scale it carries. */
const ZERO = /^-?0(?:\.0+)?$/;

/**
 * Which way net worth moved, read from the digits of the canonical string.
 *
 * A flat month is its own answer: presenting `0.00` as a rise would tell a
 * reader — and a screen reader — that something happened when nothing did.
 */
export function deltaDirection(value: string): 'down' | 'flat' | 'up' {
  if (ZERO.test(value)) {
    return 'flat';
  }

  return value.startsWith('-') ? 'down' : 'up';
}
