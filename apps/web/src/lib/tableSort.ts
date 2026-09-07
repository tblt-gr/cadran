export type SortDirection = 'asc' | 'desc';

export function toggleSort(
  currentColumn: string,
  currentDirection: SortDirection,
  column: string,
): { column: string; direction: SortDirection } {
  if (currentColumn === column) {
    return { column, direction: currentDirection === 'asc' ? 'desc' : 'asc' };
  }

  return { column, direction: 'asc' };
}

export function compareText(
  left: string,
  right: string,
  locale: string,
  direction: SortDirection,
): number {
  const result = left.localeCompare(right, locale, { sensitivity: 'base' });

  return direction === 'asc' ? result : -result;
}

/**
 * Orders backend decimal strings exactly, including signed values and scales
 * beyond the range a binary number can distinguish.
 */
export function compareDecimalString(
  left: string | null,
  right: string | null,
  direction: SortDirection,
): number {
  if (left === null && right === null) {
    return 0;
  }

  if (left === null) {
    return 1;
  }

  if (right === null) {
    return -1;
  }

  const result = compareDecimals(left, right);

  return direction === 'asc' ? result : -result;
}
import { compareDecimals } from './decimal';
