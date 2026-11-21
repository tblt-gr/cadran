import type {
  CreateTransactionRequest,
  CreateTransferRequest,
  Transaction,
} from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { useAccountDetailMutations } from './useAccountDetailMutations';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  getSession: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const saved = { id: '00000000-0000-7000-8000-0000000000f1' } as Transaction;
const body = { rawLabel: 'Courses' } as CreateTransactionRequest;
const transferBody = { label: 'Epargne' } as unknown as CreateTransferRequest;

function ok(status = 201) {
  return Promise.resolve({ data: saved, response: new Response('{}', { status }) });
}

function refused(status: number, problem: Record<string, unknown> = {}) {
  return Promise.resolve({
    data: undefined,
    error: problem,
    response: new Response(JSON.stringify(problem), { status }),
  });
}

function setup(reloadAfterWrite: () => Promise<void> = vi.fn().mockResolvedValue(undefined)) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const callbacks = { onTransactionSaved: vi.fn(), onTransferSaved: vi.fn() };
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const hook = renderHook(
    () => useAccountDetailMutations({ accountId: 'a1', reloadAfterWrite, ...callbacks }),
    { wrapper },
  );

  return { ...hook, callbacks };
}

function keyOf(mock: ReturnType<typeof vi.fn>, call: number): string {
  return mock.mock.calls[call]?.[0].headers['Idempotency-Key'];
}

describe('useAccountDetailMutations idempotency', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    document.cookie = 'csrf_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });

  it('sends a key with the auth headers on both creates', async () => {
    document.cookie = 'csrf_token=tok';
    api.createTransaction.mockImplementation(() => ok());
    api.createTransfer.mockImplementation(() => ok());
    const { result } = setup();

    await act(() => result.current.saveTransaction.mutateAsync(body));
    await act(() => result.current.saveTransfer.mutateAsync(transferBody));

    for (const mock of [api.createTransaction, api.createTransfer]) {
      const call = mock.mock.calls[0]?.[0];
      expect(call.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
      expect(call.headers['X-CSRF-TOKEN']).toBe('tok');
    }
  });

  it('retries a lost or refused save with the same key, and a reverted edit gets its first key back', async () => {
    api.createTransaction
      .mockImplementationOnce(() => Promise.reject(new TypeError('lost')))
      .mockImplementationOnce(() => refused(422))
      .mockImplementationOnce(() => refused(500))
      .mockImplementationOnce(() => refused(500))
      .mockImplementationOnce(() => ok(200));
    const { callbacks, result } = setup();
    const edited = { rawLabel: 'Autre' } as CreateTransactionRequest;

    for (const sent of [body, body, edited, body]) {
      await act(() => result.current.saveTransaction.mutateAsync(sent).catch(() => undefined));
    }
    await act(() => result.current.saveTransaction.mutateAsync(body));

    expect(keyOf(api.createTransaction, 1)).toBe(keyOf(api.createTransaction, 0));
    expect(keyOf(api.createTransaction, 2)).not.toBe(keyOf(api.createTransaction, 0));
    expect(keyOf(api.createTransaction, 3)).toBe(keyOf(api.createTransaction, 0));
    expect(keyOf(api.createTransaction, 4)).toBe(keyOf(api.createTransaction, 0));
    expect(callbacks.onTransactionSaved).toHaveBeenCalledOnce();
  });

  it('uses a fresh key for a new draft after success or release', async () => {
    api.createTransfer.mockImplementation(() => ok());
    const { result } = setup();

    await act(() => result.current.saveTransfer.mutateAsync(transferBody));
    await act(() => result.current.saveTransfer.mutateAsync(transferBody));
    expect(keyOf(api.createTransfer, 1)).not.toBe(keyOf(api.createTransfer, 0));

    api.createTransaction.mockImplementation(() => refused(500));
    await act(() => result.current.saveTransaction.mutateAsync(body).catch(() => undefined));
    act(() => result.current.transactionDraft.release());
    await act(() => result.current.saveTransaction.mutateAsync(body).catch(() => undefined));
    expect(keyOf(api.createTransaction, 1)).not.toBe(keyOf(api.createTransaction, 0));
  });

  it('keeps the key across a CSRF refresh', async () => {
    api.createTransaction
      .mockImplementationOnce(() => refused(403, { type: '/problems/csrf-token' }))
      .mockImplementationOnce(() => ok());
    api.getSession.mockImplementation(() => ok(200));
    const { result } = setup();

    await act(() => result.current.saveTransaction.mutateAsync(body));

    expect(api.createTransaction).toHaveBeenCalledTimes(2);
    expect(keyOf(api.createTransaction, 1)).toBe(keyOf(api.createTransaction, 0));
  });

  it('keeps a committed transfer successful when the follow-up read fails', async () => {
    api.createTransfer.mockImplementation(() => ok());
    const { result } = setup(vi.fn().mockRejectedValue(new Error('read failed')));

    await act(() => result.current.saveTransfer.mutateAsync(transferBody));

    await waitFor(() => expect(result.current.saveTransfer.isSuccess).toBe(true));
  });
});
