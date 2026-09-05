/**
 * Present a backend-owned percent without binary floating point.
 *
 * Trailing zeros are dropped from the canonical decimal string unless a
 * presentation scale is asked for. The interface never multiplies a ratio
 * itself and never rounds a source figure: `fractionDigits` only pads or
 * trims an already-rounded display string.
 */
export function formatSharePercent(percent: string, options?: { fractionDigits?: number }): string {
  const negative = percent.startsWith('-');
  const unsigned = negative ? percent.slice(1) : percent;
  const [whole, fraction = ''] = unsigned.split('.');
  const digits = options?.fractionDigits;
  const shown =
    digits === undefined
      ? fraction.replace(/0+$/, '')
      : fraction.padEnd(digits, '0').slice(0, digits);
  const body = shown === '' ? whole : `${whole},${shown}`;

  return `${negative ? '−' : ''}${body} %`;
}
