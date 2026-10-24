/**
 * The entry convention for a signed money movement: the user types the amount
 * and the direction is implied by what they chose (a transaction nature or a
 * category type), so an expense is typed `42.10` and stored `-42.10`.
 *
 * Storage does not change: inflow positive, outflow negative, exact canonical
 * decimals. Nothing here goes through `Number`. When the direction is unknown
 * the typed text is returned as is: the sign is never guessed.
 */
import type { CategoryType } from '@cadran/api-client';
import { asOutflow, isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';

export type AmountDirection = 'INFLOW' | 'OUTFLOW';

/** Natures whose direction is implied; an adjustment can go either way. */
export function directionForNature(nature: string): AmountDirection | null {
  if (nature === 'EXPENSE' || nature === 'FEE') return 'OUTFLOW';
  if (nature === 'INCOME') return 'INFLOW';
  return null;
}

export function directionForCategoryType(type: CategoryType | null): AmountDirection | null {
  if (type === 'EXPENSE') return 'OUTFLOW';
  if (type === 'INCOME') return 'INFLOW';
  return null;
}

/** The nature decides when it implies a direction, otherwise the chosen category does. */
export function resolveDirection(
  nature: string | null,
  categoryType: CategoryType | null,
): AmountDirection | null {
  return (
    (nature === null ? null : directionForNature(nature)) ?? directionForCategoryType(categoryType)
  );
}

/**
 * Typed amount to the signed canonical decimal. An outflow takes its minus
 * whether or not the user typed one; an inflow keeps what was typed, so a
 * contradicting minus stays visible for the sign checks to refuse. Zero, a
 * blank or a half-typed entry is returned unchanged.
 */
export function toSignedAmount(typed: string, direction: AmountDirection | null): string {
  if (direction !== 'OUTFLOW' || !isCanonicalDecimal(typed) || isZeroDecimal(typed)) {
    return typed;
  }

  return asOutflow(typed);
}

/** A stored signed amount as the user would type it: the sign matching the direction is dropped. */
export function toTypedAmount(signed: string, direction: AmountDirection | null): string {
  if (!isCanonicalDecimal(signed) || isZeroDecimal(signed)) return signed;
  if (direction === 'OUTFLOW' && signed.startsWith('-')) return signed.slice(1);

  return signed;
}
