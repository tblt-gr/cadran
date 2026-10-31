import type { AccountKind } from '@cadran/api-client';
import type { PortfolioTab } from './PortfolioTabs';

const known: readonly PortfolioTab[] = [
  'positions',
  'operations',
  'valuations',
  'cash',
  'performance',
  'account',
  'contract',
];

/** The account tab is the contract tab of a life insurance, and the reverse. */
export function tabForKind(tab: PortfolioTab, kind: AccountKind): PortfolioTab {
  if (tab !== 'account' && tab !== 'contract') return tab;
  return kind === 'INSURANCE_CONTRACT' ? 'contract' : 'account';
}

/** The tab named by `?tab=`; Positions is the default and also answers the former Répartition links. */
export function activeTab(): PortfolioTab {
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'allocation') return 'positions';
  return known.find((id) => id === tab) ?? 'positions';
}
