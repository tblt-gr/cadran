import type { QueryClient } from '@tanstack/react-query';

/** Everything an operation, or a takeover, changes about a portfolio account. */
export function invalidatePortfolioAccount(client: QueryClient, accountId: string) {
  return Promise.all([
    client.invalidateQueries({ queryKey: ['portfolio-summary', accountId] }),
    client.invalidateQueries({ queryKey: ['life-insurance-summary', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-allocation', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-valuation-history', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-performance', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-performance-annual', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolios'] }),
    client.invalidateQueries({ queryKey: ['holdings'] }),
    client.invalidateQueries({ queryKey: ['instrument-holding'] }),
    client.invalidateQueries({ queryKey: ['portfolio-cash', accountId] }),
    client.invalidateQueries({ queryKey: ['life-insurance-contract', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-positions', accountId] }),
    client.invalidateQueries({ queryKey: ['full-surrender-positions', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-operations', accountId] }),
    client.invalidateQueries({ queryKey: ['portfolio-operation'] }),
    client.invalidateQueries({ queryKey: ['portfolio-transfer-candidates'] }),
    client.invalidateQueries({ queryKey: ['account', accountId] }),
  ]);
}
