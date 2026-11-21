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
import type { Editor } from './transactionEditorState';
import { useTransactionMutations } from './useTransactionMutations';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  duplicateTransaction: vi.fn(),
  getSession: vi.fn(),
  updateTransaction: vi.fn(),
  voidTransaction: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const saved = { id: '00000000-0000-7000-8000-0000000000f1', version: 1 } as Transaction;
const body = { rawLabel: 'Courses' } as CreateTransactionRequest;
const transferBody = { label: 'Epargne' } as unknown as CreateTransferRequest;

function ok<T>(data: T, status = 201) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function refused(status: number, problem: Record<string, unknown> = {}) {
  return Promise.resolve({
    data: undefined,
    error: problem,
    response: new Response(JSON.stringify(problem), { status }),
  });
}

function setup(
  editor: Editor = 'create',
  reloadAfterWrite: () => Promise<void> = vi.fn().mockResolvedValue(undefined),
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const callbacks = { onSaved: vi.fn(), onTransferSaved: vi.fn(), onVoided: vi.fn() };
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const hook = renderHook(
    () => useTransactionMutations({ editor, ...callbacks, reloadAfterWrite }),
    { wrapper },
  );

  return { ...hook, callbacks };
}

function keyOf(mock: ReturnType<typeof vi.fn>, call: number): string {
  return mock.mock.calls[call]?.[0].headers['Idempotency-Key'];
}

describe('useTransactionMutations idempotency', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    document.cookie = 'csrf_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });

  it('sends a key with the auth headers on a transaction create and on a transfer create', async () => {
    document.cookie = 'csrf_token=tok';
    api.createTransaction.mockImplementation(() => ok(saved));
    api.createTransfer.mockImplementation(() => ok(saved));
    const { result } = setup();

    await act(() => result.current.save.mutateAsync(body));
    await act(() => result.current.transferSave.mutateAsync(transferBody));

    for (const mock of [api.createTransaction, api.createTransfer]) {
      const call = mock.mock.calls[0]?.[0];
      expect(call.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
      expect(call.headers['X-CSRF-TOKEN']).toBe('tok');
      expect(call.credentials).toBe('include');
    }
  });

  it('keeps the key after an ambiguous failure, a server error and a refusal, then replays', async () => {
    api.createTransaction
      .mockImplementationOnce(() => Promise.reject(new TypeError('lost')))
      .mockImplementationOnce(() => refused(500))
      .mockImplementationOnce(() => refused(422, { type: '/problems/validation' }))
      .mockImplementationOnce(() => ok(saved, 200));
    const { callbacks, result } = setup();

    for (let attempt = 0; attempt < 3; attempt += 1) {
      await act(() => result.current.save.mutateAsync(body).catch(() => undefined));
    }
    expect(callbacks.onSaved).not.toHaveBeenCalled();
    await act(() => result.current.save.mutateAsync(body));

    const keys = [0, 1, 2, 3].map((index) => keyOf(api.createTransaction, index));
    expect(new Set(keys).size).toBe(1);
    expect(callbacks.onSaved).toHaveBeenCalledOnce();
  });

  it('gives a changed body its own key and restores the first key when reverted', async () => {
    api.createTransfer.mockImplementation(() => refused(500));
    const { result } = setup();
    const edited = { label: 'Autre' } as unknown as CreateTransferRequest;

    for (const sent of [transferBody, edited, transferBody]) {
      await act(() => result.current.transferSave.mutateAsync(sent).catch(() => undefined));
    }

    expect(keyOf(api.createTransfer, 1)).not.toBe(keyOf(api.createTransfer, 0));
    expect(keyOf(api.createTransfer, 2)).toBe(keyOf(api.createTransfer, 0));
  });

  it('uses a fresh key for a new draft once the previous one succeeded or was released', async () => {
    api.createTransaction.mockImplementation(() => ok(saved));
    const { result } = setup();

    await act(() => result.current.save.mutateAsync(body));
    await act(() => result.current.save.mutateAsync(body));
    expect(keyOf(api.createTransaction, 1)).not.toBe(keyOf(api.createTransaction, 0));

    api.createTransfer.mockImplementation(() => refused(500));
    await act(() => result.current.transferSave.mutateAsync(transferBody).catch(() => undefined));
    act(() => result.current.transferDraft.release());
    await act(() => result.current.transferSave.mutateAsync(transferBody).catch(() => undefined));
    expect(keyOf(api.createTransfer, 1)).not.toBe(keyOf(api.createTransfer, 0));
  });

  it('keeps the key across a CSRF refresh and re-reads the token for the retried call', async () => {
    document.cookie = 'csrf_token=stale';
    api.createTransfer
      .mockImplementationOnce(() => refused(403, { type: '/problems/csrf-token' }))
      .mockImplementationOnce(() => ok(saved));
    api.getSession.mockImplementation(() => {
      document.cookie = 'csrf_token=fresh';
      return ok({});
    });
    const { result } = setup();

    await act(() => result.current.transferSave.mutateAsync(transferBody));

    expect(api.createTransfer).toHaveBeenCalledTimes(2);
    expect(keyOf(api.createTransfer, 1)).toBe(keyOf(api.createTransfer, 0));
    expect(api.createTransfer.mock.calls[0]?.[0].headers['X-CSRF-TOKEN']).toBe('stale');
    expect(api.createTransfer.mock.calls[1]?.[0].headers['X-CSRF-TOKEN']).toBe('fresh');
  });

  it('does not report a committed create as failed when a follow-up read fails', async () => {
    api.createTransaction.mockImplementation(() => ok(saved));
    api.createTransfer.mockImplementation(() => ok(saved));
    const { callbacks, result } = setup(
      'create',
      vi.fn().mockRejectedValue(new Error('read failed')),
    );

    await act(() => result.current.save.mutateAsync(body));
    await act(() => result.current.transferSave.mutateAsync(transferBody));

    await waitFor(() => expect(result.current.save.isSuccess).toBe(true));
    expect(result.current.transferSave.isSuccess).toBe(true);
    expect(callbacks.onSaved).toHaveBeenCalledOnce();
  });

  it('leaves updates, voids and duplicates without a key', async () => {
    api.updateTransaction.mockImplementation(() => ok(saved, 200));
    api.voidTransaction.mockImplementation(() => ok(saved, 200));
    api.duplicateTransaction.mockImplementation(() => ok(saved, 201));
    const { result } = setup(saved);

    await act(() => result.current.save.mutateAsync({ version: 1 } as never));
    await act(() => result.current.voidMutation.mutateAsync(saved));
    await act(() => result.current.duplicate.mutateAsync(saved));

    for (const mock of [api.updateTransaction, api.voidTransaction, api.duplicateTransaction]) {
      expect(mock.mock.calls[0]?.[0].headers['Idempotency-Key']).toBeUndefined();
    }
    expect(api.createTransaction).not.toHaveBeenCalled();
  });
});
