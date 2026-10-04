import type { PortfolioTab } from './PortfolioTabs';

/** The tab named by `?tab=`; Positions is the default. */
export function activeTab(): PortfolioTab {
  const tab = new URLSearchParams(window.location.search).get('tab');
  return tab === 'operations' || tab === 'cash' || tab === 'valuations' || tab === 'performance'
    ? tab
    : 'positions';
}
