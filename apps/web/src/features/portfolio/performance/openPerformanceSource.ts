import type { PortfolioOverviewSource } from '@cadran/api-client';

/** Opens the existing operation or valuation detail; keeps `year` when the URL already has it. */
export function openPerformanceSource(
  accountId: string,
  asOf: string,
  source: PortfolioOverviewSource,
) {
  const year = new URLSearchParams(window.location.search).get('year');
  const params = new URLSearchParams({
    asOf,
    tab: source.kind === 'OPERATION' ? 'operations' : 'valuations',
    [source.kind === 'OPERATION' ? 'operation' : 'valuation']: source.id,
  });
  if (year) params.set('year', year);
  window.history.pushState({}, '', `/portfolios/${accountId}?${params}`);
  window.dispatchEvent(new PopStateEvent('popstate'));
}
