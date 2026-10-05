import type { PortfolioTab } from './PortfolioTabs';

const known: readonly PortfolioTab[] = [
  'performance',
  'cash',
  'positions',
  'operations',
  'valuations',
  'allocation',
  'contract',
];

/** The tab named by `?tab=`; Performance is the default. */
export function activeTab(): PortfolioTab {
  const tab = new URLSearchParams(window.location.search).get('tab');
  return known.find((id) => id === tab) ?? 'performance';
}
