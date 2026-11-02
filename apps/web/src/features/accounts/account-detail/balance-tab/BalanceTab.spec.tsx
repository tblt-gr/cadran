import type { Account, AccountBalanceSnapshot } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BalanceTab } from './BalanceTab';

const api = vi.hoisted(() => ({
  listAccountBalances: vi.fn(),
  withdrawAccountBalance: vi.fn(),
  correctAccountBalance: vi.fn(),
  recordAccountBalance: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const manual: AccountBalanceSnapshot = {
  id: '00000000-0000-7000-8000-0000000000b1',
  accountId: 'courant',
  asOf: '2026-01-15',
  amount: { value: '100.00', assetCode: 'EUR' },
  source: 'MANUAL',
  reconciliationStatus: 'UNRECONCILED',
  comment: null,
  active: true,
  version: 3,
  recordedAt: '2026-10-17T09:00:00+00:00',
  supersededAt: null,
};
const reconciled: AccountBalanceSnapshot = {
  ...manual,
  id: '00000000-0000-7000-8000-0000000000b2',
  asOf: '2026-02-15',
  source: 'IMPORT',
  reconciliationStatus: 'RECONCILED',
  amount: { value: '12345678901234567890.123456789', assetCode: 'EUR' },
};
const withdrawn: AccountBalanceSnapshot = {
  ...manual,
  id: '00000000-0000-7000-8000-0000000000b3',
  asOf: '2025-12-31',
  active: false,
};

const account = {
  id: 'courant',
  label: 'Compte courant',
  status: 'ACTIVE',
  assetCode: 'EUR',
  openedOn: '2020-01-01',
  closedOn: null,
  valuation: {
    accountId: 'courant',
    requestedOn: '2026-09-24',
    asOf: null,
    amount: null,
    display: null,
    belowDisplayStep: false,
    basis: null,
    derivation: null,
    reason: null,
    source: null,
    ageDays: null,
    quality: 'MISSING',
    reconciliationStatus: null,
    snapshotId: null,
    version: null,
  },
} as unknown as Account;

function ok(value: unknown, status = 200) {
  return Promise.resolve({ data: value, response: new Response('{}', { status }) });
}

function page(items: unknown[], total = items.length) {
  return ok({ items, page: 1, pageSize: 100, total });
}

function renderTab(tabAccount: Account = account) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  return {
    invalidate,
    ...render(
      <QueryClientProvider client={queryClient}>
        <BalanceTab account={tabAccount} />
      </QueryClientProvider>,
    ),
  };
}

async function loaded() {
  await waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
}

beforeEach(() => {
  vi.clearAllMocks();
  api.listAccountBalances.mockImplementation(() => page([manual, reconciled, withdrawn]));
});
afterEach(cleanup);

describe('BalanceTab', () => {
  it('shows the real chrome while loading, then every balance with text statuses', async () => {
    api.listAccountBalances.mockImplementation(() => new Promise(() => undefined));
    renderTab();
    expect(screen.getByRole('heading', { name: 'Solde' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Rapprochement' })).toBeTruthy();
    expect(screen.getByRole('table').closest('[aria-busy="true"]')).not.toBeNull();
    expect(screen.queryByText('100,00 €')).toBeNull();
    cleanup();

    api.listAccountBalances.mockImplementation(() => page([manual, reconciled, withdrawn]));
    renderTab();
    await loaded();
    const rows = within(screen.getByRole('table')).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(3);
    expect(within(rows[0]!).getByText('Non rapproché')).toBeTruthy();
    expect(within(rows[0]!).getByText('Actif')).toBeTruthy();
    expect(within(rows[0]!).getByText('Saisie')).toBeTruthy();
    expect(within(rows[1]!).getByText('Rapproché')).toBeTruthy();
    expect(rows[1]!.textContent).toMatch(/12\s345\s678\s901\s234\s567\s890,12/);
    expect(within(rows[2]!).getByText('Retiré')).toBeTruthy();
    expect(within(rows[2]!).queryByRole('button')).toBeNull();
  });

  it('shows an explicit empty state', async () => {
    api.listAccountBalances.mockImplementation(() => page([]));
    renderTab();
    expect(await screen.findByText('Aucun solde enregistré')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Enregistrer un solde' })).toBeTruthy();
  });

  it('shows a retry alert and recovers', async () => {
    api.listAccountBalances.mockImplementationOnce(() => ok({}, 500));
    renderTab();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch('Les soldes sont indisponibles');
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await screen.findByRole('table');
    expect(api.listAccountBalances).toHaveBeenCalledTimes(2);
  });

  it('renders an uncomputable amount as a dash on a greyed row, never zero', async () => {
    api.listAccountBalances.mockImplementation(() => page([{ ...manual, amount: null }]));
    renderTab();
    await loaded();
    const row = within(screen.getByRole('table')).getAllByRole('row')[1]!;
    expect(row.textContent).toMatch('Non calculable');
    expect(row.textContent).not.toMatch(/0[,.]00/);
    expect(row.className).toMatch(/greyed/);
  });

  it('notes a truncated list', async () => {
    api.listAccountBalances.mockImplementation(() => page([manual], 150));
    renderTab();
    expect(await screen.findByText(/1 relevés affichés sur 150/)).toBeTruthy();
  });

  it('withdraws after an inline confirmation and refreshes the figures', async () => {
    api.withdrawAccountBalance.mockImplementation(() => ok({ ...manual, active: false }));
    const { invalidate } = renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour le relevé du 15/01/2026' }));
    fireEvent.click(await screen.findByRole('button', { name: /Retirer le relevé du/ }));
    expect(api.withdrawAccountBalance).not.toHaveBeenCalled();
    const confirm = await screen.findByRole('button', { name: 'Retirer le relevé' });
    await waitFor(() => expect(document.activeElement).toBe(confirm));
    fireEvent.click(confirm);
    await waitFor(() =>
      expect(api.withdrawAccountBalance).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: 'courant', snapshotId: manual.id },
          body: { version: 3 },
        }),
      ),
    );
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['net-worth'] }));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['account-balances'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['account'] });
  });

  it('cancels a withdrawal without calling the API', async () => {
    renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour le relevé du 15/01/2026' }));
    fireEvent.click(await screen.findByRole('button', { name: /Retirer le relevé du/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Annuler' }));
    expect(api.withdrawAccountBalance).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Retirer le relevé' })).toBeNull();
  });

  it('reports a stale withdrawal through the existing messages', async () => {
    api.withdrawAccountBalance.mockImplementation(() =>
      ok({ type: 'about:blank', title: 'Conflict', status: 409, code: 'STALE_VERSION' }, 409),
    );
    renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour le relevé du 15/01/2026' }));
    fireEvent.click(await screen.findByRole('button', { name: /Retirer le relevé du/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Retirer le relevé' }));
    const alerts = await screen.findAllByRole('alert');
    expect(alerts.length).toBeGreaterThan(0);
  });

  it('offers no correction on an imported balance but opens a manual one in correction mode', async () => {
    Element.prototype.scrollIntoView = vi.fn();
    api.correctAccountBalance.mockImplementation(() => ok({ ...manual, version: 4 }));
    const { invalidate } = renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour le relevé du 15/02/2026' }));
    expect(screen.queryByRole('button', { name: /Modifier le relevé/ })).toBeNull();
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' });

    fireEvent.click(screen.getByRole('button', { name: 'Actions pour le relevé du 15/01/2026' }));
    fireEvent.click(await screen.findByRole('button', { name: /Modifier le relevé du/ }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/Correction du relevé du 15\/01\/2026/)).toBeTruthy();
    expect((within(dialog).getByLabelText(/Montant/) as HTMLInputElement).value).toBe('100.00');
    fireEvent.change(within(dialog).getByLabelText(/Montant/), { target: { value: '110.50' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer la correction' }));
    await waitFor(() =>
      expect(api.correctAccountBalance).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: 'courant', snapshotId: manual.id },
          body: expect.objectContaining({ amount: '110.50', version: 3 }),
        }),
      ),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['net-worth'] });
  });

  it('records a balance from the toolbar and refreshes the figures', async () => {
    api.recordAccountBalance.mockImplementation(() => ok({ ...manual, id: 'new' }, 201));
    const { invalidate } = renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer un solde' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/Date/), { target: { value: '2026-09-01' } });
    fireEvent.change(within(dialog).getByLabelText(/Montant/), { target: { value: '250.00' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer le solde' }));
    await waitFor(() =>
      expect(api.recordAccountBalance).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: 'courant' },
          body: expect.objectContaining({ asOf: '2026-09-01', amount: '250.00', version: null }),
        }),
      ),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['net-worth'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['accounts'] });
  });

  it.each(['ARCHIVED', 'CLOSED'])('disables recording on a %s account', async (status) => {
    renderTab({ ...account, status } as Account);
    await loaded();
    expect(
      (screen.getByRole('button', { name: 'Enregistrer un solde' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});
