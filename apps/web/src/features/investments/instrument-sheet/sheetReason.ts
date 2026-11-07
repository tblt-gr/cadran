import type { InstrumentHoldingReason } from '@cadran/api-client';
export function sheetReason(reason: InstrumentHoldingReason): string {
  switch (reason) {
    case 'UNKNOWN_LOT_COST':
      return "Plus-value réalisée non calculable : coût d'origine inconnu";
    case 'HISTORY_TOO_LARGE':
      return 'Historique trop volumineux pour ce compte';
    case 'NO_VALUATION':
      return 'Aucune valorisation disponible';
    case 'MISSING_PRICE':
      return 'Prix manquant';
    case 'MIXED_ASSETS':
      return 'Devises différentes : gain total non calculable';
    default:
      return 'Non calculable';
  }
}
