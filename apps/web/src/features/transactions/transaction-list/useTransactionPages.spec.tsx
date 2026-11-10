import type { Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useTransactionPages } from './useTransactionPages';

const api = vi.hoisted(() => ({ listTransactions: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
const row = (id: string) => ({ id }) as Transaction;
const success = (ids: string[], nextCursor: string | null) =>
  Promise.resolve({
    data: { items: ids.map(row), nextCursor },
    response: { ok: true },
  });
function setup(query = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return renderHook(({ filters }) => useTransactionPages(filters, false), {
    initialProps: { filters: query },
    wrapper,
  });
}

describe('useTransactionPages', () => {
  it('appends in stable order, deduplicates and guards concurrent loads', async () => {
    let finish: (() => void) | undefined;
    api.listTransactions
      .mockImplementationOnce(() => success(['first'], 'next'))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            finish = () => resolve(success(['first', 'second'], null));
          }),
      );
    const { result } = setup();
    await waitFor(() => expect(result.current.items.map((x) => x.id)).toEqual(['first']));
    act(() => {
      result.current.loadMore();
      result.current.loadMore();
    });
    await waitFor(() => expect(api.listTransactions).toHaveBeenCalledTimes(2));
    expect(result.current.items.map((x) => x.id)).toEqual(['first']);
    await act(async () => finish?.());
    await waitFor(() => expect(result.current.items.map((x) => x.id)).toEqual(['first', 'second']));
  });

  it('retains rows on next-page errors and retries that cursor', async () => {
    api.listTransactions
      .mockImplementationOnce(() => success(['first'], 'next'))
      .mockResolvedValueOnce({ response: { ok: false, status: 500 } })
      .mockImplementationOnce(() => success(['second'], null));
    const { result } = setup();
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => result.current.loadMore());
    await waitFor(() => expect(result.current.transactions.isError).toBe(true));
    expect(result.current.items.map((x) => x.id)).toEqual(['first']);
    act(() => result.current.loadMore());
    await waitFor(() => expect(result.current.items).toHaveLength(2));
    expect(api.listTransactions.mock.calls[2]?.[0].query.cursor).toBe('next');
  });

  it('isolates filters changed during a request and restarts from the first cursor', async () => {
    let finish: (() => void) | undefined;
    api.listTransactions
      .mockImplementationOnce(() => success(['old'], 'next'))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            finish = () => resolve(success(['late'], null));
          }),
      )
      .mockImplementationOnce(() => success(['new'], null));
    const { result, rerender } = setup({ search: 'old' });
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => result.current.loadMore());
    await waitFor(() => expect(api.listTransactions).toHaveBeenCalledTimes(2));
    rerender({ filters: { search: 'new' } });
    await waitFor(() => expect(result.current.items.map((x) => x.id)).toEqual(['new']));
    await act(async () => finish?.());
    expect(result.current.items.map((x) => x.id)).toEqual(['new']);
    expect(api.listTransactions.mock.calls[2]?.[0].query.cursor).toBeUndefined();
  });

  it('returns to an earlier filter from its first page rather than reviving its old cursor chain', async () => {
    api.listTransactions
      .mockImplementationOnce(() => success(['old'], 'next'))
      .mockImplementationOnce(() => success(['older'], null))
      .mockImplementationOnce(() => success(['new'], null))
      .mockImplementationOnce(() => success(['fresh'], 'fresh-next'));
    const { result, rerender } = setup({ q: 'old' });
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => result.current.loadMore());
    await waitFor(() => expect(result.current.items).toHaveLength(2));
    rerender({ filters: { q: 'new' } });
    await waitFor(() => expect(result.current.items.map((item) => item.id)).toEqual(['new']));
    rerender({ filters: { q: 'old' } });
    await waitFor(() => expect(result.current.items.map((item) => item.id)).toEqual(['fresh']));
    expect(api.listTransactions.mock.calls[3]?.[0].query.cursor).toBeUndefined();
  });
});
