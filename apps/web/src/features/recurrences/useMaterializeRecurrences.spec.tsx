import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useMaterializeRecurrences } from './useMaterializeRecurrences';

const api = vi.hoisted(() => ({ materializeRecurrences: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function counts(created: number, booked: number) {
  return {
    data: {
      recurrencesExamined: 1,
      transactionsCreated: created,
      transactionsBooked: booked,
      skippedClosedPeriod: 0,
      skippedRefused: 0,
      monthStart: '2026-11-01',
      monthEnd: '2026-11-30',
    },
    response: new Response(null, { status: 200 }),
  };
}

function setup() {
  const queryClient = new QueryClient();
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  return { invalidate, wrapper };
}

describe('useMaterializeRecurrences', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('posts once per application load, not on every render', async () => {
    api.materializeRecurrences.mockResolvedValue(counts(0, 0));
    const { wrapper } = setup();
    const { rerender } = renderHook(() => useMaterializeRecurrences(), { wrapper });
    rerender();
    rerender();

    await waitFor(() => expect(api.materializeRecurrences).toHaveBeenCalledOnce());
    expect(api.materializeRecurrences.mock.calls[0]?.[0].body).toEqual({});
  });

  it('invalidates the ledger families when something was created or booked', async () => {
    api.materializeRecurrences.mockResolvedValue(counts(2, 0));
    const { invalidate, wrapper } = setup();
    renderHook(() => useMaterializeRecurrences(), { wrapper });

    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['transactions'] }));
    for (const key of [
      'accounts',
      'account-balances',
      'net-worth',
      'monthly-ledger',
      'monthly-recap',
      'annual-report',
      'recurrences',
      'recurrence-occurrences',
    ]) {
      expect(invalidate).toHaveBeenCalledWith({ queryKey: [key] });
    }
  });

  it('invalidates nothing when the run changed nothing', async () => {
    api.materializeRecurrences.mockResolvedValue(counts(0, 0));
    const { invalidate, wrapper } = setup();
    renderHook(() => useMaterializeRecurrences(), { wrapper });

    await waitFor(() => expect(api.materializeRecurrences).toHaveBeenCalledOnce());
    await Promise.resolve();
    expect(invalidate).not.toHaveBeenCalled();
  });

  it('fails silently on a server error and on a network failure', async () => {
    api.materializeRecurrences.mockResolvedValueOnce({
      error: { status: 500 },
      response: new Response(null, { status: 500 }),
    });
    const { invalidate, wrapper } = setup();
    expect(() => renderHook(() => useMaterializeRecurrences(), { wrapper })).not.toThrow();
    await waitFor(() => expect(api.materializeRecurrences).toHaveBeenCalledOnce());
    expect(invalidate).not.toHaveBeenCalled();

    cleanup();
    api.materializeRecurrences.mockRejectedValueOnce(new Error('offline'));
    renderHook(() => useMaterializeRecurrences(), { wrapper: setup().wrapper });
    await waitFor(() => expect(api.materializeRecurrences).toHaveBeenCalledTimes(2));
  });
});
