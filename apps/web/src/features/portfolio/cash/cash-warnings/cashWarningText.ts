import type { PortfolioCashWarning } from '@cadran/api-client';
import {
  compareDecimals,
  formatAmount,
  formatCalendarNumericDay,
  isZeroDecimal,
  roundDecimal,
} from '@/lib/decimal';

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

function plural(count: number, one: string, many: string): string {
  return count === 1 ? one : many;
}

export function warningSentence(warning: PortfolioCashWarning, locale: string): string {
  const n = warning.count;
  switch (warning.code) {
    case 'NEGATIVE_CASH':
      return warning.firstDate
        ? `Solde négatif à partir du ${formatCalendarNumericDay(warning.firstDate)} (${n} ${plural(n, 'opération', 'opérations')})`
        : `Solde négatif après ${n} ${plural(n, 'opération', 'opérations')}`;
    case 'UNLINKED_TRANSFER_IN':
      return `${n} ${plural(n, 'virement entrant n’est lié', 'virements entrants ne sont liés')} à aucun versement`;
    case 'UNLINKED_TRANSFER_OUT':
      return `${n} ${plural(n, 'virement sortant n’est lié', 'virements sortants ne sont liés')} à aucun retrait`;
    case 'UNLINKED_EXTERNAL_FLOW':
      return `${n} ${plural(n, 'versement ou retrait sans virement lié', 'versements ou retraits sans virement lié')} (un historique antérieur au suivi est légitime)`;
    case 'LINKED_TRANSFER_CHANGED':
      return `${n} ${plural(n, 'opération liée à un virement annulé ou modifié', 'opérations liées à un virement annulé ou modifié')}`;
    case 'NON_TRANSFER_TRANSACTION':
      return `${n} ${plural(n, 'transaction de revenu, dépense, remboursement ou frais enregistrée', 'transactions de revenu, dépense, remboursement ou frais enregistrées')} sur ce compte avant le suivi par portefeuille`;
    case 'ROUNDING_RESIDUALS': {
      const total = warning.amount ? ` : ${residualTotal(warning.amount, locale)}` : '';
      return `${n} ${plural(n, 'opération avec un résidu d’arrondi', 'opérations avec un résidu d’arrondi')}${total}`;
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

/** A non-zero residual is never shown as zero: below the display precision it is bounded instead. */
function residualTotal(amount: { assetCode: string; value: string }, locale: string): string {
  const digits = displayDigits(amount.assetCode, locale);
  if (isZeroDecimal(amount.value) || !isZeroDecimal(roundDecimal(amount.value, digits))) {
    return `total ${formatAmount(amount.value, amount.assetCode, locale)}`;
  }
  const step = digits === 0 ? '1' : `0.${'0'.repeat(digits - 1)}1`;
  const bound = formatAmount(step, amount.assetCode, locale);

  return compareDecimals(amount.value, '0') < 0
    ? `total inférieur à ${bound} en valeur absolue`
    : `total inférieur à ${bound}`;
}
