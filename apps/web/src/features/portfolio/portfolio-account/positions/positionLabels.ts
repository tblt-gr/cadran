import type { PortfolioPosition } from '@cadran/api-client';

export const valueReasonLabels = {
  NO_VALUATION: 'Aucune valorisation',
  PARTIAL_VALUATION: 'Valorisation partielle',
  NOT_DETAILED: 'Valeur non détaillée',
  QUANTITY_CHANGED: 'Quantité modifiée depuis la valorisation',
} as const;

/** A closed position is only listed when the toggle asks for it: nothing is held any more. */
export function isClosedPosition(position: PortfolioPosition): boolean {
  return !/[1-9]/.test(position.quantity);
}
