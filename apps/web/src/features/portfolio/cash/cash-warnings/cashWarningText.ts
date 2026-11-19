import type { PortfolioCashWarning } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatAmount, formatCalendarNumericDay, isZeroDecimal, roundDecimal } from '@/lib/decimal';

type Code = PortfolioCashWarning['code'];

export const informativeCodes: readonly Code[] = ['UNLINKED_EXTERNAL_FLOW', 'ROUNDING_RESIDUALS'];

/** Where the sources of a warning live: portfolio operations or account transactions. */
export function sourceKind(code: Code): 'operation' | 'transaction' {
  return code === 'UNLINKED_TRANSFER_IN' ||
    code === 'UNLINKED_TRANSFER_OUT' ||
    code === 'NON_TRANSFER_TRANSACTION'
    ? 'transaction'
    : 'operation';
}

export function warningSentence(
  t: TFunction,
  warning: PortfolioCashWarning,
  locale: string,
): string {
  const count = warning.count;
  switch (warning.code) {
    case 'NEGATIVE_CASH':
      return warning.firstDate
        ? t('portfolio.cash.warnings.NEGATIVE_CASH_FROM', {
            count,
            date: formatCalendarNumericDay(warning.firstDate),
          })
        : t('portfolio.cash.warnings.NEGATIVE_CASH', { count });
    case 'UNLINKED_TRANSFER_IN':
    case 'UNLINKED_TRANSFER_OUT':
    case 'UNLINKED_EXTERNAL_FLOW':
    case 'LINKED_TRANSFER_CHANGED':
    case 'NON_TRANSFER_TRANSACTION':
      return t(`portfolio.cash.warnings.${warning.code}`, { count });
    case 'ROUNDING_RESIDUALS': {
      const total = warning.amount
        ? t('portfolio.cash.warnings.roundingTotal', {
            amount: formatAmount(warning.amount.value, warning.amount.assetCode, locale),
          })
        : '';
      return `${t('portfolio.cash.warnings.ROUNDING_RESIDUALS', { count })}${total}`;
    }
  }
}

function displayDigits(assetCode: string, locale: string): number {
  try {
    return (
      new Intl.NumberFormat(locale, { style: 'currency', currency: assetCode }).resolvedOptions()
        .maximumFractionDigits ?? 2
    );
  } catch {
    return 2;
  }
}

/** A residual that rounds to zero at the display precision is not worth showing. */
export function isHiddenWarning(warning: PortfolioCashWarning, locale: string): boolean {
  if (warning.code !== 'ROUNDING_RESIDUALS' || !warning.amount) return false;
  const digits = displayDigits(warning.amount.assetCode, locale);

  return isZeroDecimal(roundDecimal(warning.amount.value, digits));
}
