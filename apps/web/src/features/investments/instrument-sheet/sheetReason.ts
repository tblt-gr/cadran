import type { InstrumentHoldingReason } from '@cadran/api-client';
import type { TFunction } from 'i18next';

export function sheetReason(reason: InstrumentHoldingReason, t: TFunction): string {
  switch (reason) {
    case 'UNKNOWN_LOT_COST':
    case 'HISTORY_TOO_LARGE':
    case 'NO_VALUATION':
    case 'MISSING_PRICE':
    case 'MIXED_ASSETS':
      return t(`investments.sheet.reasons.${reason}`);
    default:
      return t('investments.sheet.reasons.default');
  }
}
