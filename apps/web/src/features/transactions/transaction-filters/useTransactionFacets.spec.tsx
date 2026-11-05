import type { TransactionFacets } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import { useTransactionFacets, type TransactionFacetsQuery } from './useTransactionFacets';

const api = vi.hoisted(() => ({ readTransactionFacets: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const FIRST_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
const SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

const OUTFLOWS: TransactionFacetsQuery = { direction: 'OUTFLOW' };
const FIRST_ACCOUNT_OUTFLOWS: TransactionFacetsQuery = {
  accountId: [FIRST_ACCOUNT],
  direction: 'OUTFLOW',
};
const SECOND_ACCOUNT_OUTFLOWS: TransactionFacetsQuery = {
  accountId: [SECOND_ACCOUNT],
  direction: 'OUTFLOW',
};

function facets(overrides: Partial<TransactionFacets> = {}): TransactionFacets {
  return {
    accountId: {},
    categoryId: {},
    nature: {},
    state: {},
    source: {},
    axis: {},
    ...overrides,
  };
}

function answer(data: TransactionFacets, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function refusal(status: number) {
  return Promise.resolve({
    data: undefined,
    error: { status },
    response: new Response('{}', { status }),
  });
}

function setup() {
  const queryClient = new QueryClient();
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  return { queryClient, wrapper };
}

/** Moves the clock, then lets the answer it released reach the hook through the query notifier. */
async function advance(milliseconds: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(milliseconds);
    await vi.advanceTimersByTimeAsync(0);
  });
}

describe('useTransactionFacets', () => {
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it('requests the counts of the filters with the session options and no paging', async () => {
    api.readTransactionFacets.mockImplementation(() =>
      answer(facets({ accountId: { [FIRST_ACCOUNT]: 3 } })),
    );
    const { wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(FIRST_ACCOUNT_OUTFLOWS, true), {
      wrapper,
    });

    await waitFor(() => expect(result.current.counts).toBeDefined());
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
    const request = api.readTransactionFacets.mock.calls[0]?.[0];
    expect(request.query).toEqual(FIRST_ACCOUNT_OUTFLOWS);
    expect(request.query).not.toHaveProperty('cursor');
    expect(request.query).not.toHaveProperty('pageSize');
    expect(request.credentials).toBe('include');
    expect(request.signal).toBeInstanceOf(AbortSignal);
    expect(result.current.counts?.accountId).toEqual({ [FIRST_ACCOUNT]: 3 });
  });

  it('is pending only until the first answer arrives', async () => {
    let resolve: (value: unknown) => void = () => {};
    api.readTransactionFacets.mockImplementation(() => new Promise((done) => (resolve = done)));
    const { wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

    expect(result.current).toEqual({ counts: undefined, pending: true });

    await act(async () => {
      resolve(await answer(facets({ state: { BOOKED: 184 } })));
    });
    await waitFor(() => expect(result.current.pending).toBe(false));
    expect(result.current.counts?.state).toEqual({ BOOKED: 184 });
  });

  it('lives in the transactions query family so a write refreshes it', async () => {
    api.readTransactionFacets.mockImplementation(() => answer(facets()));
    const { queryClient, wrapper } = setup();
    renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());
    const keys = queryClient
      .getQueryCache()
      .findAll({ queryKey: ['transactions', 'facets'] })
      .map((query) => query.queryKey);
    expect(keys).toEqual([['transactions', 'facets', OUTFLOWS]]);
    // The refetch that follows a write skips only the queries of a later page, told by `cursor`.
    const second = keys[0]?.[1] as { cursor?: string } | undefined;
    expect(second?.cursor).toBeUndefined();

    api.readTransactionFacets.mockImplementation(() => answer(facets({ nature: { EXPENSE: 2 } })));
    await act(async () => {
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
    });
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(2);
  });

  it('asks again only once the filters have settled for 250 ms', async () => {
    vi.useFakeTimers();
    api.readTransactionFacets.mockImplementation(() => answer(facets()));
    const { wrapper } = setup();
    const { rerender } = renderHook(({ query }) => useTransactionFacets(query, true), {
      initialProps: { query: OUTFLOWS },
      wrapper,
    });
    await advance(0);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    rerender({ query: FIRST_ACCOUNT_OUTFLOWS });
    await advance(249);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    // A newer change restarts the wait, so a burst of clicks costs one request.
    rerender({ query: SECOND_ACCOUNT_OUTFLOWS });
    await advance(249);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    await advance(1);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(2);
    expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual(SECOND_ACCOUNT_OUTFLOWS);
  });

  it('keeps the counts on screen while the next answer is on its way', async () => {
    vi.useFakeTimers();
    let resolveNext: (value: unknown) => void = () => {};
    api.readTransactionFacets.mockImplementationOnce(() =>
      answer(facets({ accountId: { [FIRST_ACCOUNT]: 3 } })),
    );
    const { wrapper } = setup();
    const { rerender, result } = renderHook(({ query }) => useTransactionFacets(query, true), {
      initialProps: { query: OUTFLOWS },
      wrapper,
    });
    await advance(0);
    expect(result.current.counts?.accountId).toEqual({ [FIRST_ACCOUNT]: 3 });

    api.readTransactionFacets.mockImplementationOnce(
      () => new Promise((done) => (resolveNext = done)),
    );
    rerender({ query: FIRST_ACCOUNT_OUTFLOWS });
    await advance(250);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(2);
    // Neither blank nor skeleton: the first answer is all the pending state is for.
    expect(result.current.pending).toBe(false);
    expect(result.current.counts?.accountId).toEqual({ [FIRST_ACCOUNT]: 3 });

    resolveNext(await answer(facets({ accountId: { [FIRST_ACCOUNT]: 2 } })));
    await advance(0);
    expect(result.current.counts?.accountId).toEqual({ [FIRST_ACCOUNT]: 2 });
  });

  it('shows no count after a refused request, and is no longer pending', async () => {
    api.readTransactionFacets.mockImplementation(() => refusal(500));
    const { wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

    await waitFor(() => expect(result.current.pending).toBe(false));
    expect(result.current.counts).toBeUndefined();
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
  });

  it('hides cached counts when a background refresh fails, then restores a successful refresh', async () => {
    api.readTransactionFacets.mockImplementationOnce(() =>
      answer(facets({ state: { BOOKED: 184 } })),
    );
    const { queryClient, wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });
    await waitFor(() => expect(result.current.counts?.state).toEqual({ BOOKED: 184 }));

    api.readTransactionFacets.mockImplementationOnce(() => refusal(500));
    await act(async () => {
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
    });
    // TanStack keeps successful data in its cache after a same-key refresh error. The panel
    // must still hide it: an unsuccessful refresh cannot establish that those counts are current.
    expect(queryClient.getQueryData(['transactions', 'facets', OUTFLOWS])).toBeDefined();
    await waitFor(() => expect(result.current).toEqual({ counts: undefined, pending: false }));

    api.readTransactionFacets.mockImplementationOnce(() =>
      answer(facets({ state: { BOOKED: 185 } })),
    );
    await act(async () => {
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
    });
    await waitFor(() => expect(result.current.counts?.state).toEqual({ BOOKED: 185 }));
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(3);
  });

  it.each([429, 404, 401])(
    'treats a %i answer as a failure, never as empty counts',
    async (status) => {
      api.readTransactionFacets.mockImplementation(() => refusal(status));
      const { wrapper } = setup();
      const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

      await waitFor(() => expect(result.current.pending).toBe(false));
      expect(result.current.counts).toBeUndefined();
    },
  );

  it('shows no count when the request never reaches the server', async () => {
    api.readTransactionFacets.mockImplementation(() => Promise.reject(new TypeError('offline')));
    const { wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

    await waitFor(() => expect(result.current.pending).toBe(false));
    expect(result.current.counts).toBeUndefined();
  });

  it('recovers its counts when a later change of filters is answered', async () => {
    vi.useFakeTimers();
    api.readTransactionFacets.mockImplementationOnce(() => refusal(500));
    const { wrapper } = setup();
    const { rerender, result } = renderHook(({ query }) => useTransactionFacets(query, true), {
      initialProps: { query: OUTFLOWS },
      wrapper,
    });
    await advance(0);
    expect(result.current).toEqual({ counts: undefined, pending: false });

    api.readTransactionFacets.mockImplementationOnce(() =>
      answer(facets({ source: { MANUAL: 5 } })),
    );
    rerender({ query: FIRST_ACCOUNT_OUTFLOWS });
    await advance(250);
    // The first failure left nothing to keep on screen, so the new key starts from pending.
    expect(result.current.pending).toBe(true);
    await advance(0);
    expect(result.current).toEqual({ counts: facets({ source: { MANUAL: 5 } }), pending: false });
  });

  it('asks nothing and shows nothing while disabled, even from a cached answer', async () => {
    api.readTransactionFacets.mockImplementation(() => answer(facets({ state: { BOOKED: 184 } })));
    const { wrapper } = setup();
    const { rerender, result } = renderHook(
      ({ enabled }) => useTransactionFacets(OUTFLOWS, enabled),
      { initialProps: { enabled: true }, wrapper },
    );
    await waitFor(() => expect(result.current.counts).toBeDefined());

    rerender({ enabled: false });
    expect(result.current).toEqual({ counts: undefined, pending: false });
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
  });

  it('shows the cached answer at once when enabled again, and refreshes it in the background', async () => {
    vi.useFakeTimers();
    api.readTransactionFacets.mockImplementationOnce(() =>
      answer(facets({ state: { BOOKED: 184 } })),
    );
    const { wrapper } = setup();
    const { rerender, result } = renderHook(
      ({ enabled }) => useTransactionFacets(OUTFLOWS, enabled),
      { initialProps: { enabled: true }, wrapper },
    );
    await advance(0);
    expect(result.current.counts?.state).toEqual({ BOOKED: 184 });

    rerender({ enabled: false });
    await advance(300);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    let resolveRefresh: (value: unknown) => void = () => {};
    api.readTransactionFacets.mockImplementationOnce(
      () => new Promise((done) => (resolveRefresh = done)),
    );
    rerender({ enabled: true });
    // The earlier answer is there from the first render back, with no skeleton while it refreshes.
    expect(result.current.pending).toBe(false);
    expect(result.current.counts?.state).toEqual({ BOOKED: 184 });
    await advance(0);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(2);

    resolveRefresh(await answer(facets({ state: { BOOKED: 185 } })));
    await advance(0);
    expect(result.current.counts?.state).toEqual({ BOOKED: 185 });
  });

  it('asks nothing for filters that change while disabled, then only for the latest ones once enabled', async () => {
    vi.useFakeTimers();
    api.readTransactionFacets.mockImplementation(() => answer(facets()));
    const { wrapper } = setup();
    const { rerender } = renderHook(({ enabled, query }) => useTransactionFacets(query, enabled), {
      initialProps: { enabled: true, query: OUTFLOWS },
      wrapper,
    });
    await advance(0);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    rerender({ enabled: false, query: FIRST_ACCOUNT_OUTFLOWS });
    await advance(300);
    rerender({ enabled: false, query: SECOND_ACCOUNT_OUTFLOWS });
    await advance(300);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(1);

    rerender({ enabled: true, query: SECOND_ACCOUNT_OUTFLOWS });
    await advance(0);
    expect(api.readTransactionFacets).toHaveBeenCalledTimes(2);
    expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual(SECOND_ACCOUNT_OUTFLOWS);
  });

  it('never asks while disabled from the start, and is not pending', () => {
    const { wrapper } = setup();
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, false), { wrapper });

    expect(result.current).toEqual({ counts: undefined, pending: false });
    expect(api.readTransactionFacets).not.toHaveBeenCalled();
  });

  it('reads as pending under the frozen preview of a reload, which never fetches', () => {
    const wrapper = ({ children }: { children: ReactNode }) => (
      <FrozenQueries>{children}</FrozenQueries>
    );
    const { result } = renderHook(() => useTransactionFacets(OUTFLOWS, true), { wrapper });

    expect(result.current).toEqual({ counts: undefined, pending: true });
    expect(api.readTransactionFacets).not.toHaveBeenCalled();
  });

  it('does not loop nor ask again for an equal query rebuilt on every render', async () => {
    vi.useFakeTimers();
    api.readTransactionFacets.mockImplementation(() => answer(facets()));
    const { wrapper } = setup();
    const { rerender } = renderHook(({ query }) => useTransactionFacets(query, true), {
      initialProps: { query: { ...FIRST_ACCOUNT_OUTFLOWS } },
      wrapper,
    });
    await advance(0);

    for (let render = 0; render < 3; render += 1) {
      rerender({ query: { ...FIRST_ACCOUNT_OUTFLOWS, accountId: [FIRST_ACCOUNT] } });
      await advance(300);
    }

    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
  });
});
