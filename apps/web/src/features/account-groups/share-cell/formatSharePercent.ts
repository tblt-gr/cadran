/**
 * Present a backend-owned percent without binary floating point.
 *
 * Trailing zeros are dropped from the canonical decimal string. The interface
 * never multiplies a ratio itself.
 */
export function formatSharePercent(percent: string): string {
  const negative = percent.startsWith('-');
  const unsigned = negative ? percent.slice(1) : percent;
  const [whole, fraction = ''] = unsigned.split('.');
  const trimmed = fraction.replace(/0+$/, '');
  const body = trimmed === '' ? whole : `${whole},${trimmed}`;

  return `${negative ? '−' : ''}${body} %`;
}
