import type { CategorizationRuleConditions } from '@cadran/api-client';
import { toSignedAmount, toTypedAmount, type AmountDirection } from '@/lib/signedAmount';

type Bounds = Pick<NonNullable<CategorizationRuleConditions['amount']>, 'max' | 'min'>;

/** The rule's direction condition as an entry direction; none matches both. */
export function ruleDirection(
  direction: CategorizationRuleConditions['direction'],
): AmountDirection | null {
  if (direction === 'OUT') return 'OUTFLOW';
  if (direction === 'IN') return 'INFLOW';
  return null;
}

/**
 * Bounds as typed, to the signed range the server compares. Outflows are
 * negative, so typing "from 50 to 200" on an outflow rule is the signed range
 * -200 to -50: the smallest outflow becomes the upper bound.
 */
export function toSignedBounds(typed: Bounds, direction: AmountDirection | null): Bounds {
  const min = typed.min === null ? null : toSignedAmount(typed.min, direction);
  const max = typed.max === null ? null : toSignedAmount(typed.max, direction);

  return direction === 'OUTFLOW' ? { max: min, min: max } : { max, min };
}

/** The inverse of {@link toSignedBounds}, for showing a stored range the way it is typed. */
export function toTypedBounds(signed: Bounds, direction: AmountDirection | null): Bounds {
  const min = signed.min === null ? null : toTypedAmount(signed.min, direction);
  const max = signed.max === null ? null : toTypedAmount(signed.max, direction);

  return direction === 'OUTFLOW' ? { max: min, min: max } : { max, min };
}

/**
 * Changes the rule direction and keeps the bounds as typed: the typed range is
 * re-signed for the new direction, so what the user sees never drifts from
 * what is stored.
 */
export function withDirection(
  conditions: CategorizationRuleConditions,
  direction: CategorizationRuleConditions['direction'],
): CategorizationRuleConditions {
  const amount = conditions.amount;
  if (!amount) return { ...conditions, direction };

  const typed = toTypedBounds(
    { max: amount.max ?? null, min: amount.min ?? null },
    ruleDirection(conditions.direction),
  );

  return {
    ...conditions,
    amount: { ...amount, ...toSignedBounds(typed, ruleDirection(direction)) },
    direction,
  };
}
