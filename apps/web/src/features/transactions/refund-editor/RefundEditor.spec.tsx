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

const transaction = { id: '00000000-0000-7000-8000-0000000000f1' } as Transaction;

const proposal: RefundableTransaction = {
  originalId: transaction.id,
  originalAmount: { value: '-42.90', assetCode: 'EUR' },
  refunded: { value: '0', assetCode: 'EUR' },
  refundable: { value: '42.90', assetCode: 'EUR' },
  proposedSplits: [],
};

function renderEditor(onSaved: () => Promise<void> = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <RefundEditor
        accounts={[account]}
        close={vi.fn()}
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
});
