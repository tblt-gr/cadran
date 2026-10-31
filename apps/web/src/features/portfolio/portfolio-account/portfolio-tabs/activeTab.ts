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

/** Former securities contract links resolve to the account overview. */
export function tabForKind(tab: PortfolioTab, kind: AccountKind): PortfolioTab {
  return tab === 'contract' && kind !== 'INSURANCE_CONTRACT' ? 'account' : tab;
}

/** Account is the default; former cash and allocation links keep a useful destination. */
export function activeTab(): PortfolioTab {
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'cash') return 'operations';
  if (tab === 'allocation') return 'positions';
  return known.find((id) => id === tab) ?? 'account';
}
