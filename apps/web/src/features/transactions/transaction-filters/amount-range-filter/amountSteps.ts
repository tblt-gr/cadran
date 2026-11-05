import { compareUnsignedDecimals, isCanonicalUnsignedDecimal } from '@/lib/decimal';

/**
 * The slider moves over fixed thresholds rather than a linear scale: a household list spans
 * coffee purchases and house deposits, and a linear track would leave everyday amounts in its
 * first pixels. Positions index this list, so no money value is ever computed from a position.
 * Position 0 is "no minimum" (zero) and the position after the last threshold is "no maximum".
 */
const AMOUNT_STEPS = [
  '5',
  '10',
  '20',
  '50',
  '100',
  '200',
  '500',
  '1000',
  '2000',
  '5000',
  '10000',
] as const;

export const LAST_POSITION = AMOUNT_STEPS.length + 1;

function stepAt(position: number): string {
  return position === 0 ? '0' : AMOUNT_STEPS[position - 1];
}

/** The minimum bound a position stands for; the first position clears the bound. */
export function minBoundAt(position: number): string {
  return position <= 0 ? '' : stepAt(Math.min(position, AMOUNT_STEPS.length));
}

/** The maximum bound a position stands for; the last position clears the bound. */
export function maxBoundAt(position: number): string {
  return position >= LAST_POSITION ? '' : stepAt(Math.max(position, 0));
}

/** The highest threshold not above the bound, so the thumb never claims a larger minimum. */
export function positionForMin(bound: string): number {
  // A bound still being typed or restored malformed from the URL leaves the thumb at rest.
  if (!isCanonicalUnsignedDecimal(bound)) return 0;
  let position = 0;
  for (let candidate = 1; candidate <= AMOUNT_STEPS.length; candidate += 1) {
    if (compareUnsignedDecimals(stepAt(candidate), bound) <= 0) position = candidate;
  }

  return position;
}

/** The lowest threshold not below the bound, so the thumb never claims a smaller maximum. */
export function positionForMax(bound: string): number {
  if (!isCanonicalUnsignedDecimal(bound)) return LAST_POSITION;
  for (let candidate = 0; candidate <= AMOUNT_STEPS.length; candidate += 1) {
    if (compareUnsignedDecimals(stepAt(candidate), bound) >= 0) return candidate;
  }

  return LAST_POSITION;
}
