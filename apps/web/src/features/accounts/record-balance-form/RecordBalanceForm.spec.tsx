import type { Account, AccountValuation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { RecordBalanceForm } from './RecordBalanceForm';

const api = vi.hoisted(() => ({
  listAccountBalances: vi.fn(),
  withdrawAccountBalance: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: 'courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  closedOn: null,
} as Account;

const valuation = {
  requestedOn: '2026-10-21',
  asOf: '2026-09-30',
  amount: { value: '500.00', assetCode: 'EUR' },
  source: 'MANUAL',
  quality: 'STALE',
  version: 3,
  derivation: null,
} as unknown as AccountValuation;

const snapshot = {
  id: '00000000-0000-7000-8000-0000000000b1',
  accountId: 'courant',
  asOf: '2026-01-15',
  amount: { value: '100.00', assetCode: 'EUR' },
  source: 'MANUAL' as const,
  reconciliationStatus: 'UNRECONCILED' as const,
  comment: 'Relevé de janvier',
  active: true,
  version: 7,
  recordedAt: '2026-10-17T09:00:00+00:00',
  supersededAt: null,
};

function listing(items: unknown[]) {
  api.listAccountBalances.mockImplementation(() =>
    Promise.resolve({
      data: { items, page: 1, pageSize: 100, total: items.length },
      response: new Response('{}', { status: 200 }),
    }),
  );
}

function renderForm() {
  const onSubmit = vi.fn();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <RecordBalanceForm
        account={account}
        onCancel={vi.fn()}
        onSubmit={onSubmit}
        pending={false}
        submitError={null}
        valuation={valuation}
      />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByText('Relevés enregistrés'));

  return onSubmit;
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => cleanup());

it('corrects a listed manual snapshot with its own version and a fixed date', async () => {
  listing([snapshot]);
  const onSubmit = renderForm();

  fireEvent.click(await screen.findByRole('button', { name: /actions pour le relevé du/i }));
  fireEvent.click(screen.getByRole('button', { name: /modifier le relevé du/i }));

  const date = screen.getByLabelText(/date/i, { selector: 'input' });
  expect(date).toHaveProperty('readOnly', true);
  expect(date).toHaveProperty('value', '2026-01-15');

  const amount = screen.getByLabelText(/montant/i, { selector: 'input' });
  expect(amount).toHaveProperty('value', '100.00');
  fireEvent.change(amount, { target: { value: '125.50' } });
  fireEvent.click(screen.getByRole('button', { name: /enregistrer la correction/i }));

  await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
  expect(onSubmit).toHaveBeenCalledWith({
    asOf: '2026-01-15',
    amount: '125.50',
    amountAssetCode: 'EUR',
    comment: 'Relevé de janvier',
    version: 7,
  });
});

it('leaves the correction and restores the new-snapshot defaults', async () => {
  listing([snapshot]);
  renderForm();

  fireEvent.click(await screen.findByRole('button', { name: /actions pour le relevé du/i }));
  fireEvent.click(screen.getByRole('button', { name: /modifier le relevé du/i }));
  fireEvent.click(screen.getByRole('button', { name: /annuler la correction/i }));

  expect(screen.getByLabelText(/date/i, { selector: 'input' })).toHaveProperty('readOnly', false);
  expect(screen.getByLabelText(/montant/i, { selector: 'input' })).toHaveProperty(
    'value',
    '500.00',
  );
});

it('does not offer to correct a snapshot that is not manual or not active', async () => {
  listing([
    { ...snapshot, id: 'a', source: 'IMPORT' },
    { ...snapshot, id: 'b', active: false },
  ]);
  renderForm();

  await waitFor(() => expect(api.listAccountBalances).toHaveBeenCalled());
  await screen.findAllByRole('row');
  // The inactive one has no menu; the imported one only offers the withdrawal.
  const triggers = screen.getAllByRole('button', { name: /actions pour le relevé du/i });
  expect(triggers).toHaveLength(1);
  fireEvent.click(triggers[0]!);
  expect(screen.queryByRole('button', { name: /modifier le relevé du/i })).toBeNull();
  expect(screen.getByRole('button', { name: /retirer le relevé du/i })).toBeTruthy();
});
