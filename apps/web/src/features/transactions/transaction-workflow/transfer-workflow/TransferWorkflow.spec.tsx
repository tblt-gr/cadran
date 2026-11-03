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

it('names the edit intent and lays the legs, essential fields and folded fields out', async () => {
  api.getTransfer.mockImplementation(() => success({ ...transfer, fee: null }));
  setup();
  await screen.findByDisplayValue('Épargne');
  const dialog = screen.getByRole('dialog', { name: 'Modifier le virement' });
  expect(dialog.textContent).toContain('Modification');
  expect(screen.getByText('Montant débité')).toBeTruthy();
  expect(screen.getByText('Montant reçu')).toBeTruthy();
  expect(screen.getByText('Frais', { selector: 'dt' })).toBeTruthy();
  expect(screen.getByText('Les comptes et les montants du virement sont conservés.')).toBeTruthy();
  const details = dialog.querySelector('details')!;
  expect(details.open).toBe(false);
  expect(details.textContent).toContain('Champs avancés');
  expect(details.contains(screen.getByLabelText('Note'))).toBe(true);
  expect(details.contains(screen.getByLabelText('Libellé'))).toBe(false);
});

it('opens the advanced fields when the transfer already holds a note', async () => {
  api.getTransfer.mockImplementation(() =>
    success({ ...transfer, fee: null, source: { ...transfer.source, note: 'À garder' } }),
  );
  setup();
  await screen.findByDisplayValue('Épargne');
  expect(document.querySelector('details')!.open).toBe(true);
});

it('opens the advanced fields when the transfer holds a fee', async () => {
  setup();
  await screen.findByDisplayValue('Épargne');
  expect(document.querySelector('details')!.open).toBe(true);
});

it('keeps an already voided transfer read-only', async () => {
  api.getTransfer.mockImplementation(() =>
    success({ ...transfer, voidedAt: '2026-09-13T12:00:00Z' }),
  );
  setup();
  await screen.findByDisplayValue('Épargne');
  expect(screen.getByRole('dialog').querySelector('fieldset')!.disabled).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(api.updateTransfer).not.toHaveBeenCalled();
});

it('shows the same layout with a disabled fieldset while the transfer loads', () => {
  api.getTransfer.mockImplementation(() => new Promise(() => {}));
  setup();
  const dialog = screen.getByRole('dialog', { name: 'Modifier le virement' });
  expect(dialog.textContent).toContain('Montant débité');
  expect(dialog.querySelector('fieldset')!.disabled).toBe(true);
  expect(
    dialog.querySelectorAll('dd [aria-hidden="true"], dd [data-skeleton]').length,
  ).toBeGreaterThan(0);
});

it('confirms a void with Annuler first and read-only legs', async () => {
  const close = vi.fn();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <TransferWorkflow id="pair" voiding close={close} onSaved={vi.fn(async () => {})} />
    </QueryClientProvider>,
  );
  await screen.findByText('Les deux mouvements et les frais du virement seront annulés ensemble.');
  const dialog = screen.getByRole('dialog');
  expect(dialog.textContent).toContain('Confirmation');
  const buttons = Array.from(dialog.querySelectorAll('fieldset button')).map((b) => b.textContent);
  expect(buttons[0]).toBe('Annuler');
  fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
  expect(close).toHaveBeenCalledOnce();
});
