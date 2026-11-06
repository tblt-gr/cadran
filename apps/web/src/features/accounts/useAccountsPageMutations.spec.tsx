import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAccountsPageMutations } from './useAccountsPageMutations';

const api = vi.hoisted(() => ({
  archiveAccount: vi.fn(),
  createAccount: vi.fn(),
  recordAccountBalance: vi.fn(),
  updateAccount: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success(data: unknown) {
  return Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
}

function setup() {
  const queryClient = new QueryClient();
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const hook = renderHook(
    () =>
      useAccountsPageMutations({
        editor: 'create',
        closeEditor: vi.fn(),
        closeArchive: vi.fn(),
        closeRecording: vi.fn(),
        notify: vi.fn(),
      }),
    { wrapper },
  );
  return { hook, invalidate };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('useAccountsPageMutations', () => {
  it('refreshes the portfolios list after creating an account, so a new investment joins fresh data', async () => {
    api.createAccount.mockImplementation(() => success({ id: 'new' }));
    const { hook, invalidate } = setup();

    await act(async () => {
      await hook.result.current.save.mutateAsync({} as never);
    });

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['accounts'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['portfolios', 'overview'] });
  });

  it('refreshes the portfolios list after archiving an account', async () => {
    api.archiveAccount.mockImplementation(() => success({ id: 'a' }));
    const { hook, invalidate } = setup();

    await act(async () => {
      await hook.result.current.archive.mutateAsync({ id: 'a', version: 1 } as Account);
    });

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['portfolios', 'overview'] });
  });

  it('opens the positions tab of a new securities account', async () => {
    api.createAccount.mockImplementation(() =>
      success({ id: 'cto-1', kind: 'PORTFOLIO', valuationMode: 'PORTFOLIO' }),
    );
    const { hook } = setup();
    const navigate = vi.spyOn(window.history, 'pushState');

    await act(async () => {
      await hook.result.current.save.mutateAsync({} as never);
    });

    expect(navigate).toHaveBeenCalledWith({}, '', '/portfolios/cto-1?tab=positions');
    navigate.mockRestore();
  });

  it('stays on the list after creating any other account, or editing a securities one', async () => {
    api.createAccount.mockImplementation(() =>
      success({ id: 'chk-1', kind: 'CHECKING', valuationMode: 'BALANCE' }),
    );
    const { hook } = setup();
    const navigate = vi.spyOn(window.history, 'pushState');

    await act(async () => {
      await hook.result.current.save.mutateAsync({} as never);
    });

    expect(navigate).not.toHaveBeenCalled();
    navigate.mockRestore();
  });
});
