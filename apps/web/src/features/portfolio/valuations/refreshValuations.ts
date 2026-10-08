import type { QueryClient } from '@tanstack/react-query';

/** A valuation change moves the list, the detail, the account value and the net worth. */
export function refreshValuations(client: QueryClient, accountId: string) {
  return Promise.all([
    client.invalidateQueries({ queryKey: ['portfolio-summary', accountId] }),
    client.invalidateQueries({ queryKey: ['life-insurance-summary', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-allocation', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-valuation-history', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-performance', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-performance-annual', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolios'] }),
    client.invalidateQueries({ queryKey: ['portfolio-valuations', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-valuation'] }),
    client.invalidateQueries({ queryKey: ['account', accountId] }),
    client.invalidateQueries({ queryKey: ['accounts'] }),
  ]);
}
