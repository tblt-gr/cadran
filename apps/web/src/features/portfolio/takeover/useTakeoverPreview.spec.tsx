import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTakeoverPreview } from './useTakeoverPreview';

const api = vi.hoisted(() => ({ previewPortfolioTakeover: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const request = {
  takenOverOn: '2026-10-31',
  lines: [
    { instrumentId: 'i-1', quantity: '5', price: '101.5000', costBasis: null, priceDate: null },
  ],
  cash: null,
};
const preview = {
  lines: [{ instrumentId: 'i-1', value: '507.50', averageUnitCost: null }],
  securitiesValue: '507.50',
  cash: '0',
  totalValue: '507.50',
  errors: [],
};
const wrapper = ({ children }: { children: ReactNode }) => (
  <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>
);

beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));
afterEach(() => {
  vi.useRealTimers();
  cleanup();
  vi.clearAllMocks();
});

describe('useTakeoverPreview', () => {
  it('waits 300 ms after the last edit, then serves the server figures', async () => {
    api.previewPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 200 }),
      data: preview,
    });
    const { result, rerender } = renderHook(
      ({ body }: { body: typeof request | null }) => useTakeoverPreview('acc-1', body),
      { wrapper, initialProps: { body: null as typeof request | null } },
    );
    rerender({ body: request });
    expect(result.current.isFetching).toBe(true);
    expect(api.previewPortfolioTakeover).not.toHaveBeenCalled();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });
    await waitFor(() => expect(result.current.data?.totalValue).toBe('507.50'));
    expect(api.previewPortfolioTakeover).toHaveBeenCalledWith(
      expect.objectContaining({ path: { accountId: 'acc-1' }, body: request }),
    );
  });

  it('stays idle without a complete request', () => {
    const { result } = renderHook(() => useTakeoverPreview('acc-1', null), { wrapper });
    expect(result.current.isFetching).toBe(false);
    expect(result.current.data).toBeUndefined();
    expect(api.previewPortfolioTakeover).not.toHaveBeenCalled();
  });

  it('reports a failure without data', async () => {
    api.previewPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 500 }),
      error: {},
    });
    const { result } = renderHook(() => useTakeoverPreview('acc-1', request), { wrapper });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.data).toBeUndefined();
  });
});
