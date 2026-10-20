import type { Transfer } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransferWorkflow } from './TransferWorkflow';

const api = vi.hoisted(() => ({
  getTransfer: vi.fn(),
  updateTransfer: vi.fn(),
  voidTransfer: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const transfer = {
  id: 'pair',
  version: 7,
  voidedAt: null,
  source: {
    id: 'debit',
    accountId: 'source',
    amount: { value: '-100.25', assetCode: 'EUR' },
    state: 'BOOKED',
    rawLabel: 'Épargne',
    bookedOn: '2026-09-12',
    valueOn: null,
    note: null,
  },
  target: { id: 'credit', accountId: 'target', amount: { value: '100.25', assetCode: 'EUR' } },
  fee: { amount: { value: '-0.75', assetCode: 'EUR' } },
} as Transfer;
const success = (data: unknown) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
beforeEach(() => {
  api.getTransfer.mockImplementation(() => success(transfer));
  api.updateTransfer.mockImplementation(() => success(transfer));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function setup() {
  const onSaved = vi.fn(async () => {});
  const close = vi.fn();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <TransferWorkflow id="pair" voiding={false} close={close} onSaved={onSaved} />
    </QueryClientProvider>,
  );
  return { close, onSaved, queryClient };
}

it('updates the complete authoritative transfer while preserving its accounts, leg amounts and exact fee', async () => {
  const { onSaved } = setup();
  await screen.findByDisplayValue('Épargne');
  fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne corrigée' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.updateTransfer).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { id: 'pair' },
        body: {
          sourceAccountId: 'source',
          targetAccountId: 'target',
          version: 7,
          bookedOn: '2026-09-12',
          valueOn: null,
          state: 'BOOKED',
          label: 'Épargne corrigée',
          note: null,
          fee: { value: '-0.75', assetCode: 'EUR' },
        },
      }),
    ),
  );
  await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
  expect(api.voidTransfer).not.toHaveBeenCalled();
});

it('preserves the transfer draft and actual stale error without silently replacing its version', async () => {
  api.updateTransfer.mockImplementation(() =>
    Promise.resolve({
      error: { type: '/problems/stale-version', detail: 'Ce virement a changé.', status: 409 },
      response: new Response('{}', { status: 409 }),
    }),
  );
  const { close } = setup();
  await screen.findByDisplayValue('Épargne');
  fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Brouillon conservé' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(await screen.findByText('Ce virement a changé.')).toBeTruthy();
  expect((screen.getByLabelText('Libellé') as HTMLInputElement).value).toBe('Brouillon conservé');
  expect(close).not.toHaveBeenCalled();
});

it('keeps the transfer read failure recoverable and prevents writes for a foreign or unavailable ID', async () => {
  api.getTransfer.mockImplementation(() =>
    Promise.resolve({
      error: { type: '/problems/not-found', detail: 'Virement introuvable.', status: 404 },
      response: new Response('{}', { status: 404 }),
    }),
  );
  setup();
  expect(await screen.findByText('Virement introuvable.')).toBeTruthy();
  expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(api.updateTransfer).not.toHaveBeenCalled();
});

it('binds the draft to its original version when an authoritative background refetch arrives', async () => {
  api.updateTransfer.mockImplementation(({ body }: { body: { version: number } }) =>
    body.version === transfer.version
      ? Promise.resolve({
          error: {
            type: '/problems/stale-version',
            detail: 'Un autre enregistrement a modifié ce virement.',
            status: 409,
          },
          response: new Response('{}', { status: 409 }),
        })
      : success(transfer),
  );
  const { queryClient, close, onSaved } = setup();
  await screen.findByDisplayValue('Épargne');
  fireEvent.change(screen.getByLabelText('Libellé'), {
    target: { value: 'Mon brouillon initial' },
  });
  api.getTransfer.mockImplementation(() =>
    success({
      ...transfer,
      version: 8,
      source: { ...transfer.source, rawLabel: 'Modification concurrente', note: 'Nouvelle note' },
      fee: { amount: { value: '-1.25', assetCode: 'EUR' } },
    }),
  );
  await act(async () => {
    await queryClient.invalidateQueries({ queryKey: ['transfer-workflow', transfer.id] });
  });
  await screen.findByText(/1,25/);
  expect(queryClient.getQueryData<Transfer>(['transfer-workflow', transfer.id])?.version).toBe(8);
  expect((screen.getByLabelText('Libellé') as HTMLInputElement).value).toBe(
    'Mon brouillon initial',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.updateTransfer).toHaveBeenCalledWith(
      expect.objectContaining({
        body: expect.objectContaining({ version: 7, label: 'Mon brouillon initial', note: null }),
      }),
    ),
  );
  expect(await screen.findByText('Un autre enregistrement a modifié ce virement.')).toBeTruthy();
  expect(close).not.toHaveBeenCalled();
  expect(onSaved).not.toHaveBeenCalled();
});
