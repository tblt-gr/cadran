/**
 * Pure, exact arithmetic for the split editor: the remaining amount and the
 * two row helpers ("assign the remainder" and "split evenly"). Nothing here
 * ever reads a decimal string as a JavaScript `Number` — every operation
 * works on canonical decimal strings or `BigInt` smallest-unit integers, the
 * same primitives the backend re-checks the submitted allocation against.
 */
import { isCanonicalDecimal, isZeroDecimal, subtractDecimals, sumDecimals } from '@/lib/decimal';
import type { SplitRowValues } from './SplitRow';

export const MIN_EVEN_SPLIT_ROWS = 2;
export const MAX_SPLIT_ROWS = 20;

/**
 * A fresh row, keyed for React reconciliation. `analyticAxes: null` means
 * "inherit the picked category's default axes" — the backend resolves it;
 * an explicit array, even empty, is a deliberate override.
 */
export function emptySplitRow(): SplitRowValues {
  return { amount: '', analyticAxes: null, categoryId: '', key: rowKey(), note: '' };
}

function rowKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `row-${Math.random().toString(36).slice(2)}`;
}

/** The amount still unallocated: the transaction total minus the sum of its rows. */
export function remainingAmount(total: string, rowAmounts: readonly string[]): string {
  return subtractDecimals(total, sumDecimals(rowAmounts));
}

/**
 * Assigns whatever remains to one row, so the full set sums exactly to
 * `total` regardless of what the other rows carry.
 */
export function assignRemainderToRow(
  total: string,
  rowAmounts: readonly string[],
  index: number,
): string[] {
  const others = rowAmounts.filter((_, position) => position !== index);
  const remainder = subtractDecimals(total, sumDecimals(others));

  return rowAmounts.map((amount, position) => (position === index ? remainder : amount));
}

/**
 * Whether {@see evenSplitRows} can serve this amount: it is an exact,
 * non-zero canonical decimal, the row count is within bounds, and the amount
 * carries no more fraction digits than the asset's display precision — a more
 * precise source figure would need rounding to spread evenly, which the
 * helper refuses to do silently.
 */
export function canEvenSplit(total: string, precision: number, rowCount: number): boolean {
  return (
    isCanonicalDecimal(total) &&
    !isZeroDecimal(total) &&
    rowCount >= MIN_EVEN_SPLIT_ROWS &&
    rowCount <= MAX_SPLIT_ROWS &&
    fractionDigits(total) <= precision
  );
}

/**
 * Splits `total` evenly across `rowCount` rows at the asset's display
 * precision `p`: `base = trunc(|total| / rowCount, p)`, and the exact
 * multiple-of-`10^-p` remainder goes one smallest unit at a time to the
 * first rows, in order. Deterministic and reproducible — the same inputs
 * always produce the same rows, independent of anything but the inputs
 * themselves.
 */
export function evenSplitRows(total: string, precision: number, rowCount: number): string[] {
  if (!canEvenSplit(total, precision, rowCount)) {
    throw new Error('An even split is unavailable for this amount, row count or precision.');
  }

  const negative = total.startsWith('-');
  const unsigned = negative ? total.slice(1) : total;
  const [integer, fraction = ''] = unsigned.split('.');
  const units = BigInt(integer + fraction.padEnd(precision, '0'));
  const rows = BigInt(rowCount);
  const base = units / rows;
  const remainderUnits = units % rows;

  return Array.from({ length: rowCount }, (_, index) => {
    const rowUnits = base + (BigInt(index) < remainderUnits ? 1n : 0n);

    return formatUnits(negative ? -rowUnits : rowUnits, precision);
  });
}

function fractionDigits(value: string): number {
  const separator = value.indexOf('.');

  return separator === -1 ? 0 : value.length - separator - 1;
}

function formatUnits(units: bigint, scale: number): string {
  const negative = units < 0n;
  const digits = (negative ? -units : units).toString().padStart(scale + 1, '0');
  const unsigned = scale === 0 ? digits : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;

  return negative && units !== 0n ? `-${unsigned}` : unsigned;
}
