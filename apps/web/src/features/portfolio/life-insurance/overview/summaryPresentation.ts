import type { LifeInsuranceSummary } from '@cadran/api-client';
import { formatAmount } from '@/lib/decimal';
export const allocationLabels = {
  EURO_FUND: 'Fonds en euros',
  UNIT_LINKED: 'Unités de compte',
  CASH: 'Liquidités',
};
export const allocationEmpty = {
  NO_VALUATION: 'Enregistrez une valorisation pour voir la répartition.',
  NOT_DETAILED: 'Répartition indisponible : la dernière valorisation ne détaille pas les supports.',
};
export const money = (value: string | null, assetCode: string) =>
  value === null ? 'Non calculable' : formatAmount(value, assetCode, 'fr');
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
