import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { invalidatePortfolioAccount } from './invalidatePortfolioAccount';

describe('invalidatePortfolioAccount', () => {
  it('refreshes every query that derives from the operations of the account', async () => {
    const client = new QueryClient();
    const spy = vi.spyOn(client, 'invalidateQueries');
    await invalidatePortfolioAccount(client, 'acc-1');
    const keys = spy.mock.calls.map(([filters]) => filters?.queryKey);
    expect(keys).toContainEqual(['portfolio-summary', 'acc-1']);
    expect(keys).toContainEqual(['portfolio-positions', 'acc-1']);
    expect(keys).toContainEqual(['portfolio-operations', 'acc-1']);
    expect(keys).toContainEqual(['portfolios']);
    expect(keys).toContainEqual(['account', 'acc-1']);
    expect(keys).toHaveLength(15);
  });
});
