import type { CreateTransactionRequest, CreateTransferRequest } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BudgetCreationModals, type BudgetCreationDraft } from './BudgetCreationModals';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

vi.mock('./useActiveBudgetAccounts', () => ({
  useActiveBudgetAccounts: () => ({ data: [], isError: false, isPending: false }),
}));

const transactionBody = { rawLabel: 'Courses' } as CreateTransactionRequest;
const transferBody = { label: 'Epargne' } as unknown as CreateTransferRequest;

vi.mock('@/features/transactions/transaction-editor/TransactionEditor', () => ({
  TransactionEditor: ({
    close,
    onSubmit,
  }: {
    close: () => void;
    onSubmit: (body: CreateTransactionRequest) => void;
  }) => (
    <>
      <button onClick={() => onSubmit(transactionBody)} type="button">
        send transaction
      </button>
      <button onClick={close} type="button">
        cancel
      </button>
    </>
  ),
}));

vi.mock('@/features/transactions/transfer-editor/TransferEditor', () => ({
  TransferEditor: ({
    onSubmit,
  }: {
    onSubmit: (body: CreateTransferRequest) => Promise<unknown>;
  }) => (
    <button onClick={() => void onSubmit(transferBody).catch(() => undefined)} type="button">
      send transfer
    </button>
  ),
}));

const expenseDraft = { kind: 'expense', row: null } as BudgetCreationDraft;
const accountDraft = { kind: 'account', row: { id: 'a1' } } as unknown as BudgetCreationDraft;

function renderModals(draft: BudgetCreationDraft, close = vi.fn()) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const onSaved = vi.fn().mockResolvedValue(undefined);
  const view = (current: BudgetCreationDraft) => (
    <QueryClientProvider client={queryClient}>
      <BudgetCreationModals
        close={close}
        draft={current}
        month="2026-09"
        onSaved={onSaved}
        today="2026-09-15"
      />
    </QueryClientProvider>
  );
  const rendered = render(view(draft));

  return {
    ...rendered,
    close,
    onSaved,
    rerenderWith: (d: BudgetCreationDraft) => rendered.rerender(view(d)),
  };
}

const keyOf = (mock: ReturnType<typeof vi.fn>, call: number) =>
  mock.mock.calls[call]?.[0].headers['Idempotency-Key'];

describe('BudgetCreationModals idempotency', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    document.cookie = 'csrf_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });

  it('sends a key with the auth headers and replays the same key after a lost response', async () => {
    document.cookie = 'csrf_token=tok';
    api.createTransaction
      .mockRejectedValueOnce(new TypeError('lost'))
      .mockResolvedValueOnce({ data: { id: 't1' }, response: new Response('{}', { status: 201 }) });
    const { close, onSaved } = renderModals(expenseDraft);

    fireEvent.click(screen.getByRole('button', { name: 'send transaction' }));
    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'send transaction' }));
    await waitFor(() => expect(close).toHaveBeenCalled());

    expect(keyOf(api.createTransaction, 0)).toMatch(/^[0-9a-f-]{36}$/);
    expect(keyOf(api.createTransaction, 1)).toBe(keyOf(api.createTransaction, 0));
    expect(api.createTransaction.mock.calls[0]?.[0].headers['X-CSRF-TOKEN']).toBe('tok');
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
  });

  it('uses a fresh key once the modal was cancelled and reopened', async () => {
    api.createTransaction.mockResolvedValue({
      data: undefined,
      response: new Response('{}', { status: 500 }),
    });
    const { close, rerenderWith } = renderModals(expenseDraft);

    fireEvent.click(screen.getByRole('button', { name: 'send transaction' }));
    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'cancel' }));
    expect(close).toHaveBeenCalledOnce();
    rerenderWith(null);
    rerenderWith(expenseDraft);
    fireEvent.click(screen.getByRole('button', { name: 'send transaction' }));
    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledTimes(2));

    expect(keyOf(api.createTransaction, 1)).not.toBe(keyOf(api.createTransaction, 0));
  });

  it('sends a key on a transfer and keeps the saved transfer successful when the refresh fails', async () => {
    api.createTransfer.mockResolvedValue({
      data: { id: 'x1' },
      response: new Response('{}', { status: 201 }),
    });
    const { onSaved } = renderModals(accountDraft);
    onSaved.mockRejectedValue(new Error('read failed'));

    fireEvent.click(screen.getByRole('button', { name: 'send transfer' }));

    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(keyOf(api.createTransfer, 0)).toMatch(/^[0-9a-f-]{36}$/);
    expect(api.createTransfer).toHaveBeenCalledOnce();
  });
});
