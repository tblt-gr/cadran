import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { PendingReconciliation } from './PendingReconciliation';
const api = vi.hoisted(() => ({ getTransaction: vi.fn(), reconcileTransaction: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});
it('books a genuinely new reviewed movement through the existing reconciliation contract', async () => {
  api.getTransaction.mockResolvedValue({
    response: new Response('{}', { status: 200 }),
    data: {
      id: 'review',
      accountId: 'own',
      rawLabel: 'Nouvel achat',
      displayLabel: 'Nouvel achat',
      state: 'PENDING',
      reviewReason: 'NO_MATCH',
      reconciliationCandidateIds: [],
      version: 4,
    },
  });
  api.reconcileTransaction.mockResolvedValue({
    response: new Response('{}', { status: 200 }),
    data: { id: 'review', state: 'BOOKED' },
  });
  const onSaved = vi.fn(async () => {});
  render(
    <QueryClientProvider client={new QueryClient()}>
      <PendingReconciliation transactionId="review" close={vi.fn()} onSaved={onSaved} />
    </QueryClientProvider>,
  );
  await screen.findByText('Nouvel achat');
  fireEvent.click(screen.getByRole('button', { name: 'Confirmer le rapprochement' }));
  await waitFor(() =>
    expect(api.reconcileTransaction).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { id: 'review' },
        body: { version: 4, matchedTransactionId: null },
      }),
    ),
  );
  await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
});
