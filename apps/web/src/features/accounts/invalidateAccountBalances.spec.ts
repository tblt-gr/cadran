import { QueryClient } from '@tanstack/react-query';
import { expect, it, vi } from 'vitest';
import { invalidateAccountBalances } from './invalidateAccountBalances';

it('refreshes the month bounds a movement write can move', async () => {
  const client = new QueryClient();
  const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

  await invalidateAccountBalances(client);

  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['monthly-bounds'] });
});
