import { roundDecimal } from './decimal';

/**
 * Present a backend-owned percent without binary floating point: rounded half
 * away from zero on the decimal string to `fractionDigits` (2 by default),
 * never through a `Number`.
 */
export function formatSharePercent(percent: string, options?: { fractionDigits?: number }): string {
  const rounded = roundDecimal(percent, options?.fractionDigits ?? 2);
  const negative = rounded.startsWith('-');
  const body = (negative ? rounded.slice(1) : rounded).replace('.', ',');

  return `${negative ? '\u2212' : ''}${body} %`;
}
