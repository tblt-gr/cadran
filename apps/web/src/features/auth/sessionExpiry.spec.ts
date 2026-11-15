import { client } from '@cadran/api-client/client';
import { QueryClient, QueryObserver } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { registerSessionExpiry } from './sessionExpiry';
import { sessionQueryKey } from './useSession';

function setup(status: number) {
  client.setConfig({
    baseUrl: 'http://localhost',
    fetch: async () => new Response('{}', { status }),
  });
  const queryClient = new QueryClient();
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  const unregister = registerSessionExpiry(client, queryClient);

  return { client, queryClient, invalidate, unregister };
}

describe('registerSessionExpiry', () => {
  it('invalidates the session query on a 401 from a feature endpoint', async () => {
    const { client, invalidate } = setup(401);

    const result = await client.get({ url: '/api/v1/transactions' });

    expect(result.response?.status).toBe(401);
    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: sessionQueryKey });
  });

  it('ignores a 401 from the session endpoint itself', async () => {
    const { client, invalidate } = setup(401);

    await client.post({ url: '/api/v1/session' });

    expect(invalidate).not.toHaveBeenCalled();
  });

  it.each([422, 500])('ignores a %i', async (status) => {
    const { client, invalidate } = setup(status);

    await client.get({ url: '/api/v1/transactions' });

    expect(invalidate).not.toHaveBeenCalled();
  });

  it('triggers a single session refetch for concurrent 401', async () => {
    const { client, queryClient } = setup(401);
    let release: () => void = () => undefined;
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    const fetchSession = vi.fn(async () => {
      await gate;

      return { authenticated: false };
    });
    // An active observer is what makes invalidation refetch, as useSession does.
    const watcher = new QueryObserver(queryClient, {
      queryKey: sessionQueryKey,
      queryFn: fetchSession,
      staleTime: Infinity,
    });
    const stop = watcher.subscribe(() => undefined);
    release();
    await vi.waitFor(() => expect(queryClient.isFetching()).toBe(0));
    fetchSession.mockClear();

    await Promise.all([
      client.get({ url: '/api/v1/accounts' }),
      client.get({ url: '/api/v1/budgets' }),
      client.get({ url: '/api/v1/goals' }),
    ]);
    await vi.waitFor(() => expect(queryClient.isFetching()).toBe(0));
    stop();

    expect(fetchSession).toHaveBeenCalledTimes(1);
  });

  it('stops intercepting once unregistered', async () => {
    const { client, invalidate, unregister } = setup(401);
    unregister();

    await client.get({ url: '/api/v1/transactions' });

    expect(invalidate).not.toHaveBeenCalled();
  });
});
