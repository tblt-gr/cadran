import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useOperationPreview } from './useOperationPreview';

const api = vi.hoisted(() => ({ previewPortfolioOperation: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('correction preview identity', () => {
  it('hides cached switch figures while refetching and after a failed refresh', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const request = { type: 'SWITCH' as const, tradeDate: '2026-09-01', legs: [] };
    api.previewPortfolioOperation.mockResolvedValueOnce({
      response: new Response('{}', { status: 200 }),
      data: {
        cashAmount: { value: '0', assetCode: 'EUR' },
        expectedIncomingAmount: { value: '1990', assetCode: 'EUR' },
      },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useOperationPreview('account-1', request), { wrapper });
    await waitFor(() => expect(result.current.data?.expectedIncomingAmount?.value).toBe('1990'));
    let complete: (value: unknown) => void = () => {};
    api.previewPortfolioOperation.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          complete = resolve;
        }),
    );
    act(() => {
      void result.current.refetch();
    });
    await waitFor(() => expect(result.current.isFetching).toBe(true));
    expect(result.current.data).toBeUndefined();
    act(() =>
      complete({
        response: new Response('{}', { status: 422 }),
        error: { code: 'position_would_go_negative' },
      }),
    );
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.data).toBeUndefined();
  });

  it('does not reuse a previous correction preview for an identical body and different original', async () => {
    const first = '00000000-0000-7000-8000-000000000001';
    const second = '00000000-0000-7000-8000-000000000002';
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const request = {
      type: 'PARTIAL_SURRENDER' as const,
      tradeDate: '2026-03-01',
      grossAmount: { value: '60', assetCode: 'EUR' },
    };
    api.previewPortfolioOperation.mockImplementation(({ query }) =>
      Promise.resolve({
        response: new Response('{}', { status: 200 }),
        data: {
          cashAmount: { value: '-60', assetCode: 'EUR' },
          netPaidAmount: { value: '60', assetCode: 'EUR' },
          expectedGrossAmount: {
            value: query.correctingOperationId === first ? '100' : '80',
            assetCode: 'EUR',
          },
          roundingResidual: null,
          legs: [],
        },
      }),
    );
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result, rerender } = renderHook(
      ({ id }) => useOperationPreview('account-1', request, id),
      { initialProps: { id: first }, wrapper },
    );
    await waitFor(() => expect(result.current.data?.expectedGrossAmount?.value).toBe('100'));
    rerender({ id: second });
    expect(result.current.data).toBeUndefined();
    await waitFor(() => expect(result.current.data?.expectedGrossAmount?.value).toBe('80'), {
      timeout: 2000,
    });
    expect(api.previewPortfolioOperation).toHaveBeenCalledTimes(2);
    expect(api.previewPortfolioOperation.mock.calls[1]![0].query).toEqual({
      correctingOperationId: second,
    });
    expect(
      client
        .getQueryCache()
        .findAll({ queryKey: ['portfolio-operation-preview', 'account-1', first] }),
    ).toHaveLength(1);
    expect(
      client
        .getQueryCache()
        .findAll({ queryKey: ['portfolio-operation-preview', 'account-1', second] })
        .some((query) => query.state.data !== undefined),
    ).toBe(true);
  });
});
