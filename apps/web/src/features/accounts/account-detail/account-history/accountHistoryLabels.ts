import type { Account, AccountValuationHistoryPoint } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
export function historyAmount(point: AccountValuationHistoryPoint, locale: string): string {
  return point.amount === null
    ? '-'
    : `${formatDecimal(point.amount, locale)}\u00a0${point.assetCode ?? ''}`;
}
export function historyQuality(point: AccountValuationHistoryPoint, t: TFunction): string {
  return t(`accounts.balances.qualities.${point.quality}`);
}
export function historyProvenance(point: AccountValuationHistoryPoint, t: TFunction): string {
  const details: string[] = [];
  if (point.reason) details.push(t(`accounts.history.reasons.${point.reason}`));
  if (point.source) details.push(t(`accounts.balances.sources.${point.source}`));
  if (point.basis === 'SNAPSHOT') details.push(t('accounts.history.held'));
  if (point.asOf)
    details.push(t('accounts.balances.asOf', { date: formatCalendarNumericDay(point.asOf) }));
  if (point.ageDays !== null) details.push(t('accounts.balances.age', { count: point.ageDays }));
  if (point.derivation) {
    details.push(
      t('accounts.balances.derivation', {
        date: formatCalendarNumericDay(point.derivation.anchorAsOf),
        count: point.derivation.movementCount,
      }),
    );
    if (point.derivation.pendingCount > 0)
      details.push(t('accounts.balances.pending', { count: point.derivation.pendingCount }));
  }
  return details.join(' · ');
}

export type HistoryAccount = Pick<Account, 'kind' | 'valuationMode'>;

/** Wording follows how the value is obtained: movements give a balance, recorded totals a value. */
export function historyKeys(account: HistoryAccount | null) {
  const snapshots = account?.valuationMode === 'SNAPSHOTS';
  return {
    title: snapshots ? 'accounts.history.titleSnapshots' : 'accounts.history.title',
    table: snapshots ? 'accounts.history.tableSnapshots' : 'accounts.history.table',
    amount: snapshots ? 'accounts.history.valueSnapshots' : 'accounts.history.balance',
  } as const;
}

export function showsManualPortfolioNotice(account: HistoryAccount): boolean {
  return (
    account.valuationMode === 'SNAPSHOTS' &&
    (account.kind === 'PORTFOLIO' || account.kind === 'INSURANCE_CONTRACT')
  );
}
