import type { LifeInsuranceSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
export const allocationKinds = ['EURO_FUND', 'UNIT_LINKED', 'CASH'] as const;
export function lifeInsuranceMoney(
  value: string | null,
  assetCode: string,
  locale: string,
  notCalculable: string,
) {
  return value === null ? notCalculable : formatAmount(value, assetCode, locale);
}
/** An amount of the contract in the active language; a missing one is told in words. */
export function useLifeInsuranceMoney() {
  const { t, i18n } = useTranslation();
  return (value: string | null, assetCode: string) =>
    lifeInsuranceMoney(value, assetCode, i18n.language, t('states.notCalculable.label'));
}
export function sourceHref(
  accountId: string,
  asOf: string,
  kind: 'OPERATION' | 'VALUATION',
  id: string,
) {
  const query = new URLSearchParams({
    asOf,
    tab: kind === 'OPERATION' ? 'operations' : 'valuations',
    [kind === 'OPERATION' ? 'operation' : 'valuation']: id,
  });
  return `/portfolios/${accountId}?${query}`;
}
export type Summary = LifeInsuranceSummary;
