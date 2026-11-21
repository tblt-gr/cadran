import type { Account, RefundableTransaction, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { RefundEditor } from './RefundEditor';

const api = vi.hoisted(() => ({
  createTransactionRefund: vi.fn(),
  getTransactionRefundable: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000a1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const transaction = {
  id: '00000000-0000-7000-8000-0000000000f1',
  displayLabel: 'Courses',
  bookedOn: '2026-09-03',
} as Transaction;

const proposal: RefundableTransaction = {
  originalId: transaction.id,
  originalAmount: { value: '-42.90', assetCode: 'EUR' },
  refunded: { value: '0', assetCode: 'EUR' },
  refundable: { value: '42.90', assetCode: 'EUR' },
  proposedSplits: [],
};

function renderEditor(onSaved: () => Promise<void> = vi.fn(), close: () => void = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <RefundEditor
        accounts={[account]}
        close={close}
        onSaved={onSaved}
        transaction={transaction}
      />
    </QueryClientProvider>,
  );
}

describe('RefundEditor', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows a loading state while the refundable balance is fetched', () => {
    api.getTransactionRefundable.mockReturnValue(new Promise(() => {}));

    renderEditor();

    expect(screen.getByRole('status')).toBeDefined();
    expect(screen.getByText(/Transaction d’origine : Courses/)).toBeTruthy();
  });

  it('shows an error when the refundable balance cannot be read', async () => {
    api.getTransactionRefundable.mockResolvedValue({
      response: { ok: false, status: 404 },
      data: undefined,
    });

    renderEditor();

    await waitFor(() => expect(screen.getByRole('alert')).toBeDefined());
  });

  it('saves a refund from the fetched proposal and reports success', async () => {
    api.getTransactionRefundable.mockResolvedValue({ response: { ok: true }, data: proposal });
    api.createTransactionRefund.mockResolvedValue({
      response: { ok: true },
      data: { ...transaction, nature: 'REFUND' },
    });
    const onSaved = vi.fn().mockResolvedValue(undefined);

    renderEditor(onSaved);

    await screen.findByLabelText('Montant');
    fireEvent.change(screen.getByLabelText('Date comptable'), { target: { value: '2026-03-14' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Remboursement' } });
    fireEvent.click(screen.getByRole('button', { name: 'Créer le remboursement' }));

    await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalled());
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
  });

  describe('idempotency', () => {
    async function submitRefund() {
      fireEvent.change(await screen.findByLabelText('Libellé'), {
        target: { value: 'Remboursement' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'Créer le remboursement' }));
    }

    const keyOf = (call: number) =>
      api.createTransactionRefund.mock.calls[call]?.[0].headers['Idempotency-Key'];

    it('sends a key with the auth headers and keeps it after an ambiguous failure', async () => {
      document.cookie = 'csrf_token=tok';
      api.getTransactionRefundable.mockResolvedValue({ response: { ok: true }, data: proposal });
      api.createTransactionRefund
        .mockRejectedValueOnce(new TypeError('lost'))
        .mockResolvedValueOnce({ response: { ok: true }, data: { ...transaction } });
      const close = vi.fn();
      renderEditor(vi.fn().mockResolvedValue(undefined), close);

      await submitRefund();
      await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledTimes(1));
      await waitFor(() =>
        expect(
          (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
            .disabled,
        ).toBe(false),
      );
      fireEvent.click(screen.getByRole('button', { name: 'Créer le remboursement' }));
      await waitFor(() => expect(close).toHaveBeenCalledOnce());

      expect(keyOf(0)).toMatch(/^[0-9a-f-]{36}$/);
      expect(keyOf(1)).toBe(keyOf(0));
      expect(api.createTransactionRefund.mock.calls[0]?.[0].headers['X-CSRF-TOKEN']).toBe('tok');
      document.cookie = 'csrf_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('keeps the refund committed when the follow-up read fails', async () => {
      api.getTransactionRefundable.mockResolvedValue({ response: { ok: true }, data: proposal });
      api.createTransactionRefund.mockResolvedValue({
        response: { ok: true },
        data: { ...transaction },
      });
      const close = vi.fn();
      renderEditor(vi.fn().mockRejectedValue(new Error('read failed')), close);

      await submitRefund();

      await waitFor(() => expect(close).toHaveBeenCalledOnce());
      expect(api.createTransactionRefund).toHaveBeenCalledOnce();
    });

    it('does not close or release the key when the server refuses the refund', async () => {
      api.getTransactionRefundable.mockResolvedValue({ response: { ok: true }, data: proposal });
      api.createTransactionRefund.mockResolvedValue({
        response: { ok: false, status: 422 },
        data: undefined,
        error: { type: '/problems/validation' },
      });
      const close = vi.fn();
      renderEditor(vi.fn().mockResolvedValue(undefined), close);

      await submitRefund();
      await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledOnce());

      expect(close).not.toHaveBeenCalled();
    });

    it('uses a fresh key for a refund editor opened again', async () => {
      api.getTransactionRefundable.mockResolvedValue({ response: { ok: true }, data: proposal });
      api.createTransactionRefund.mockResolvedValue({
        response: { ok: false, status: 500 },
        data: undefined,
      });

      const first = renderEditor();
      await submitRefund();
      await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledTimes(1));
      first.unmount();
      renderEditor();
      await submitRefund();
      await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledTimes(2));

      expect(keyOf(1)).not.toBe(keyOf(0));
    });
  });
});
