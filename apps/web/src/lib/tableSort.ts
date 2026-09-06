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
 * Orders backend decimal strings for a table. `Number` is used only as a sort
 * key — nothing here is shown as a figure.
 */
export function compareDecimalString(
  left: string | null,
  right: string | null,
  direction: SortDirection,
): number {
  const leftValue = left === null ? null : Number(left);
  const rightValue = right === null ? null : Number(right);
  const leftKnown = leftValue !== null && Number.isFinite(leftValue);
  const rightKnown = rightValue !== null && Number.isFinite(rightValue);

  if (!leftKnown && !rightKnown) {
    return 0;
  }

  if (!leftKnown) {
    return 1;
  }

  if (!rightKnown) {
    return -1;
  }

  return direction === 'asc' ? leftValue - rightValue : rightValue - leftValue;
}
