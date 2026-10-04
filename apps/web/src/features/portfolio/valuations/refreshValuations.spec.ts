import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { refreshValuations } from './refreshValuations';

describe('refreshValuations', () => {
  it('invalidates the performance of the account', async () => {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    await refreshValuations(client, 'account-1');

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['portfolio-performance', 'account-1'] });
  });

  it('invalidates the annual performance of the account', async () => {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    await refreshValuations(client, 'account-1');

    expect(invalidate).toHaveBeenCalledWith({
      queryKey: ['portfolio-performance-annual', 'account-1'],
    });
  });

  it('invalidates the value history of the account', async () => {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    await refreshValuations(client, 'account-1');

    expect(invalidate).toHaveBeenCalledWith({
      queryKey: ['portfolio-valuation-history', 'account-1'],
    });
  });
});
