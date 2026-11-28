import type { CreateRecurrenceRequest, Recurrence, RecurrenceCandidate } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { Editor } from './recurrenceEditorState';
import { RecurrenceRequestError } from './recurrenceError';
import { useRecurrenceMutations } from './useRecurrenceMutations';

const api = vi.hoisted(() => ({
  archiveRecurrence: vi.fn(),
  confirmRecurrence: vi.fn(),
  dismissRecurrenceCandidate: vi.fn(),
  restoreRecurrence: vi.fn(),
  restoreRecurrenceCandidate: vi.fn(),
  updateRecurrence: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const recurrence = {
  id: '00000000-0000-7000-8000-0000000000r1',
  version: 3,
  autoCreate: false,
} as Recurrence;
const candidate = { fingerprint: 'fp-1' } as RecurrenceCandidate;
const body: CreateRecurrenceRequest = {
  accountId: '00000000-0000-7000-8000-0000000000a1',
  label: 'Loyer',
  counterparty: null,
  expectedAmount: '-650.00',
  amountTolerance: '0',
  intervalKind: 'MONTHLY',
  dayOfPeriod: 5,
  firstExpectedOn: '2026-01-05',
};

function ok<T>(data: T, status = 200) {
  return Promise.resolve({
    data,
    response: new Response(status === 204 ? null : JSON.stringify(data), { status }),
  });
}

function refused(status: number, problem: Record<string, unknown> = {}) {
  return Promise.resolve({
    data: undefined,
    error: { status, ...problem },
    response: new Response(JSON.stringify(problem), { status }),
  });
}

function setup(editor: Editor = 'create') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  const callbacks = { onArchived: vi.fn(), onRestored: vi.fn(), onSaved: vi.fn() };
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const hook = renderHook(() => useRecurrenceMutations({ editor, ...callbacks }), { wrapper });
  const invalidated = () => invalidate.mock.calls.map(([filters]) => filters?.queryKey?.[0]);

  return { ...hook, callbacks, invalidated, queryClient };
}

describe('useRecurrenceMutations', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('creates a recurrence with one idempotency key per body and refreshes the ledger', async () => {
    api.confirmRecurrence.mockImplementation(() => ok({ ...recurrence, autoCreate: false }, 201));
    const { callbacks, invalidated, result } = setup('create');

    act(() => result.current.save.mutate(body));
    await waitFor(() => expect(result.current.notice).toBe('La récurrence a été enregistrée.'));

    const call = api.confirmRecurrence.mock.calls[0]?.[0];
    expect(call.body).toEqual(body);
    expect(call.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    expect(callbacks.onSaved).toHaveBeenCalledOnce();
    await waitFor(() => expect(invalidated()).toContain('accounts'));
    expect(invalidated()).toEqual(expect.arrayContaining(['recurrences', 'recurrence-candidates']));
    expect(invalidated()).not.toContain('transactions');
  });

  it('marks a confirmed candidate at once and also refreshes transactions when it is automatic', async () => {
    api.confirmRecurrence.mockImplementation(() => ok({ ...recurrence, autoCreate: true }, 201));
    const { invalidated, result } = setup(candidate);

    act(() => result.current.save.mutate({ ...body, autoCreate: true }));
    await waitFor(() => expect(result.current.confirmed.has('fp-1')).toBe(true));

    await waitFor(() => expect(invalidated()).toContain('transactions'));
  });

  it('updates an existing recurrence by id and spares the balances unless it is automatic', async () => {
    api.updateRecurrence.mockImplementation(() => ok({ ...recurrence, autoCreate: false }));
    const { callbacks, invalidated, result } = setup(recurrence);

    act(() => result.current.save.mutate({ ...body, version: 3 } as never));
    await waitFor(() => expect(callbacks.onSaved).toHaveBeenCalledOnce());

    expect(api.confirmRecurrence).not.toHaveBeenCalled();
    expect(api.updateRecurrence.mock.calls[0]?.[0].path).toEqual({ id: recurrence.id });
    await waitFor(() => expect(invalidated()).toContain('recurrence-candidates'));
    expect(invalidated()).not.toContain('accounts');
    expect(result.current.confirmed.size).toBe(0);
  });

  it('refreshes balances and transactions after updating an automatic recurrence', async () => {
    api.updateRecurrence.mockImplementation(() => ok({ ...recurrence, autoCreate: true }));
    const { invalidated, result } = setup(recurrence);

    act(() => result.current.save.mutate(body));

    await waitFor(() => expect(invalidated()).toContain('transactions'));
    expect(invalidated()).toContain('accounts');
  });

  it('exposes a 422 as an invalid submission and leaves the cache and callbacks alone', async () => {
    api.confirmRecurrence.mockImplementation(() =>
      refused(422, { type: '/problems/validation', detail: 'label: blank' }),
    );
    const { callbacks, invalidated, queryClient, result } = setup('create');
    queryClient.setQueryData(['recurrences'], { items: ['kept'] });

    act(() => result.current.save.mutate(body));
    await waitFor(() => expect(result.current.submitErrorKind).toBe('invalid'));

    expect(result.current.save.error).toBeInstanceOf(RecurrenceRequestError);
    expect((result.current.save.error as RecurrenceRequestError).detail).toBe('label: blank');
    expect(callbacks.onSaved).not.toHaveBeenCalled();
    expect(result.current.notice).toBeNull();
    expect(invalidated()).toEqual([]);
    expect(queryClient.getQueryData(['recurrences'])).toEqual({ items: ['kept'] });
  });

  it('classifies an archived-recurrence 409 and keeps the cached data', async () => {
    api.updateRecurrence.mockImplementation(() =>
      refused(409, { type: '/problems/recurrences.archived' }),
    );
    const { invalidated, queryClient, result } = setup(recurrence);
    queryClient.setQueryData(['recurrences'], { items: ['kept'] });

    act(() => result.current.save.mutate(body));
    await waitFor(() => expect(result.current.submitErrorKind).toBe('archived'));

    expect(invalidated()).toEqual([]);
    expect(queryClient.getQueryData(['recurrences'])).toEqual({ items: ['kept'] });
  });

  it('classifies the refusal of an identical recurrence', async () => {
    api.confirmRecurrence.mockImplementation(() =>
      refused(409, { type: '/problems/recurrences.duplicate' }),
    );
    const { callbacks, result } = setup('create');

    act(() => result.current.save.mutate(body));

    await waitFor(() => expect(result.current.submitErrorKind).toBe('duplicate'));
    expect(callbacks.onSaved).not.toHaveBeenCalled();
  });

  it('reports a plain network error for an unclassified failure', async () => {
    api.confirmRecurrence.mockRejectedValue(new TypeError('offline'));
    const { result } = setup('create');

    act(() => result.current.save.mutate(body));

    await waitFor(() => expect(result.current.submitErrorKind).toBe('network'));
  });

  it('dismisses a candidate, offers the undo and refreshes the suggestions only', async () => {
    api.dismissRecurrenceCandidate.mockImplementation(() => ok({}, 204));
    const { invalidated, result } = setup();

    act(() => result.current.dismiss.mutate(candidate));
    await waitFor(() => expect(result.current.dismissed).toBe(candidate));

    expect(api.dismissRecurrenceCandidate.mock.calls[0]?.[0].body).toEqual({ fingerprint: 'fp-1' });
    expect(result.current.notice).toBe('La proposition a été masquée.');
    expect(invalidated()).toEqual(['recurrence-candidates']);
  });

  it('keeps the suggestion hidden when dismissing fails', async () => {
    api.dismissRecurrenceCandidate.mockImplementation(() => refused(409));
    const { invalidated, result } = setup();

    act(() => result.current.dismiss.mutate(candidate));
    await waitFor(() => expect(result.current.dismiss.isError).toBe(true));

    expect(result.current.dismissed).toBeNull();
    expect(result.current.notice).toBeNull();
    expect(invalidated()).toEqual([]);
  });

  it('undoes a dismissal and clears the undo banner', async () => {
    api.dismissRecurrenceCandidate.mockImplementation(() => ok({}, 204));
    api.restoreRecurrenceCandidate.mockImplementation(() => ok({}, 204));
    const { invalidated, result } = setup();
    act(() => result.current.dismiss.mutate(candidate));
    await waitFor(() => expect(result.current.dismissed).toBe(candidate));

    act(() => result.current.undoDismissal.mutate(candidate));
    await waitFor(() => expect(result.current.dismissed).toBeNull());

    expect(api.restoreRecurrenceCandidate.mock.calls[0]?.[0].path).toEqual({
      fingerprint: 'fp-1',
    });
    expect(result.current.notice).toBe('La proposition est de nouveau visible.');
    expect(invalidated()).toEqual(['recurrence-candidates', 'recurrence-candidates']);
  });

  it('keeps the undo banner when restoring a dismissal fails', async () => {
    api.dismissRecurrenceCandidate.mockImplementation(() => ok({}, 204));
    api.restoreRecurrenceCandidate.mockImplementation(() => refused(500));
    const { result } = setup();
    act(() => result.current.dismiss.mutate(candidate));
    await waitFor(() => expect(result.current.dismissed).toBe(candidate));

    act(() => result.current.undoDismissal.mutate(candidate));
    await waitFor(() => expect(result.current.undoDismissal.isError).toBe(true));

    expect(result.current.dismissed).toBe(candidate);
  });

  it('archives a recurrence at its version and refreshes the recurrences', async () => {
    api.archiveRecurrence.mockImplementation(() => ok(recurrence));
    const { callbacks, invalidated, result } = setup();

    act(() => result.current.archive.mutate(recurrence));
    await waitFor(() => expect(callbacks.onArchived).toHaveBeenCalledOnce());

    expect(api.archiveRecurrence.mock.calls[0]?.[0]).toMatchObject({
      path: { id: recurrence.id },
      body: { version: 3 },
    });
    expect(result.current.notice).toBe('La récurrence a été archivée.');
    expect(invalidated()).toEqual(['recurrences']);
  });

  it('keeps the recurrence and its cache when archiving is refused with a 409', async () => {
    api.archiveRecurrence.mockImplementation(() => refused(409));
    const { callbacks, invalidated, queryClient, result } = setup();
    queryClient.setQueryData(['recurrences'], { items: ['kept'] });

    act(() => result.current.archive.mutate(recurrence));
    await waitFor(() => expect(result.current.archive.isError).toBe(true));

    expect(callbacks.onArchived).not.toHaveBeenCalled();
    expect(invalidated()).toEqual([]);
    expect(queryClient.getQueryData(['recurrences'])).toEqual({ items: ['kept'] });
  });

  it('restores an archived recurrence at its version and refreshes the recurrences', async () => {
    api.restoreRecurrence.mockImplementation(() => ok(recurrence));
    const { callbacks, invalidated, result } = setup();

    act(() => result.current.restore.mutate(recurrence));
    await waitFor(() => expect(callbacks.onRestored).toHaveBeenCalledOnce());

    expect(api.restoreRecurrence.mock.calls[0]?.[0]).toMatchObject({
      path: { id: recurrence.id },
      body: { version: 3 },
    });
    expect(result.current.notice).toBe('La récurrence a été restaurée.');
    expect(invalidated()).toEqual(['recurrences']);
  });

  it('keeps the archived state when restoring fails', async () => {
    api.restoreRecurrence.mockImplementation(() => refused(500));
    const { callbacks, result } = setup();

    act(() => result.current.restore.mutate(recurrence));
    await waitFor(() => expect(result.current.restore.isError).toBe(true));

    expect(callbacks.onRestored).not.toHaveBeenCalled();
    expect(result.current.notice).toBeNull();
  });
});
