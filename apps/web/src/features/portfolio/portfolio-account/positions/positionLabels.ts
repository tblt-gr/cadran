import type { PortfolioPosition } from '@cadran/api-client';
import type { TFunction } from 'i18next';

export type ValueReason = NonNullable<PortfolioPosition['valueReason']>;

export function valueReasonLabel(t: TFunction, reason: ValueReason): string {
  return t(`portfolio.account.valueReason.${reason}`);
}

/** A closed position is only listed when the toggle asks for it: nothing is held any more. */
export function isClosedPosition(position: PortfolioPosition): boolean {
  return !/[1-9]/.test(position.quantity);
}
