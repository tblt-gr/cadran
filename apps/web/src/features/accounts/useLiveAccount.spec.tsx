import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider, type InfiniteData } from '@tanstack/react-query';
import { act, cleanup, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { useLiveAccount } from './useLiveAccount';

afterEach(cleanup);

const stale = { id: 'a1', version: 1 } as Account;

function setup() {
  const queryClient = new QueryClient();
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  let renders = 0;
  const hook = renderHook(
    () => {
      renders += 1;
      return useLiveAccount(stale);
    },
    { wrapper },
  );
  return { queryClient, hook, renders: () => renders };
}

describe('useLiveAccount', () => {
  it('presents the fresher row once the overview rows are updated', () => {
    const { queryClient, hook } = setup();

    act(() => {
      queryClient.setQueryData<InfiniteData<{ items: Account[] }>>(
        ['accounts', 'overview-rows', 'g'],
        { pages: [{ items: [{ id: 'a1', version: 2 } as Account] }], pageParams: [] },
      );
    });

    expect(hook.result.current?.version).toBe(2);
  });

  it('does not re-render on cache activity of unrelated queries', () => {
    const { queryClient, hook, renders } = setup();
    const before = renders();

    act(() => {
      queryClient.setQueryData(['account-balances', 'a1'], { items: [] });
      queryClient.getQueryCache().notify({
        type: 'observerOptionsUpdated',
        query: queryClient.getQueryCache().find({ queryKey: ['account-balances', 'a1'] })!,
        observer: {} as never,
      });
    });

    expect(renders()).toBe(before);
    expect(hook.result.current).toBe(stale);
  });
});
