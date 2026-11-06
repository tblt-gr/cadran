import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BulkRequestError, useBulkEdit } from './useBulkEdit';

const api = vi.hoisted(() => ({ bulkEditTransactions: vi.fn(), getSession: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const body = { changes: { note: null }, scope: { type: 'IDS' as const, ids: ['a'] } };

describe('useBulkEdit', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('reuses the idempotency key for the same body and reloads after a write', async () => {
    const reload = vi.fn(() => Promise.resolve());
    api.bulkEditTransactions
      .mockResolvedValueOnce({
        data: undefined,
        error: {},
        response: new Response('{}', { status: 500 }),
      })
      .mockResolvedValueOnce({
        data: { requestedCount: 1, changed: 1, fieldsChanged: ['note'], skipped: [] },
        response: new Response('{}', { status: 200 }),
      });
    const { result } = renderHook(() => useBulkEdit({ reloadAfterWrite: reload }), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(body).catch(() => undefined);
    });
    await waitFor(() => expect(result.current.error).toBeInstanceOf(BulkRequestError));
    await act(async () => {
      await result.current.mutateAsync(body);
    });

    const keys = api.bulkEditTransactions.mock.calls.map(
      (call) => call[0].headers['Idempotency-Key'],
    );
    expect(keys[0]).toBe(keys[1]);
    expect(api.bulkEditTransactions.mock.calls[0]?.[0].body).toEqual(body);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('classifies a scope-changed problem with its actual count', async () => {
    api.bulkEditTransactions.mockResolvedValue({
      data: undefined,
      error: { type: '/problems/transactions.bulk-scope-changed', actualCount: 7 },
      response: new Response('{}', { status: 409 }),
    });
    const { result } = renderHook(() => useBulkEdit({ reloadAfterWrite: vi.fn() }), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(body).catch(() => undefined);
    });

    await waitFor(() => expect(result.current.error).toBeInstanceOf(BulkRequestError));
    const error = result.current.error as BulkRequestError;
    expect(error.kind).toBe('scopeChanged');
    expect(error.actualCount).toBe(7);
  });
});
