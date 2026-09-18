import type { Account, AccountReconciliation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ReconciliationPanel } from './ReconciliationPanel';

const api = vi.hoisted(() => ({
  readAccountReconciliation: vi.fn(),
  resolveAccountReconciliation: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  openedOn: '2026-01-10',
  valuation: {
    asOf: '2026-09-03',
    snapshotId: '00000000-0000-7000-8000-0000000000b1',
    version: 3,
    reconciliationStatus: 'UNRECONCILED',
  },
} as unknown as Account;

const pending = {
  id: '00000000-0000-7000-8000-0000000000e1',
  rawLabel: 'Carte en attente',
  amount: { value: '-12.00', assetCode: 'EUR' },
  bookedOn: '2026-09-02',
};

function view(overrides: Partial<AccountReconciliation> = {}): AccountReconciliation {
  return {
    accountId: account.id,
    snapshotId: '00000000-0000-7000-8000-0000000000b1',
    snapshotVersion: 3,
    reconciliationStatus: 'UNRECONCILED',
    periodStart: '2026-09-01',
    periodEnd: '2026-09-03',
    openingBalance: { value: '1000.00', assetCode: 'EUR' },
    movementsTotal: { value: '170.25', assetCode: 'EUR' },
    closingBalance: { value: '1165.00', assetCode: 'EUR' },
    discrepancy: { value: '-5.25', assetCode: 'EUR' },
    nonCalculableReason: null,
    pendingCount: 1,
    pendingTransactions: [pending as never],
    availableResolutions: ['OVERRIDE', 'ADJUST'],
    ...overrides,
  };
}

function ok(data: AccountReconciliation) {
  return { data, response: { ok: true, status: 200 } };
}

function failure(status: number, type: string) {
  return { error: { type }, response: { ok: false, status } };
}

function mount(onReconciled = vi.fn()) {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <ReconciliationPanel account={account} onReconciled={onReconciled} />
    </QueryClientProvider>,
  );

  return onReconciled;
}

describe('ReconciliationPanel', () => {
  beforeEach(() => {
    api.readAccountReconciliation.mockResolvedValue(ok(view()));
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows the exact figures, the signed discrepancy and the pending rows', async () => {
    mount();

    expect(await screen.findByText(/Carte en attente/)).toBeTruthy();
    expect(screen.getByText(/Écart/, { selector: 'dt' })).toBeTruthy();
    expect(screen.getByTestId('discrepancy').textContent).toMatch(/−|-/);
    expect(screen.getByTestId('discrepancy').textContent).toContain('5,25');
    expect(screen.getByText(/1 transaction en attente/)).toBeTruthy();
  });

  it('shows a reason and no figure when the comparison is not calculable', async () => {
    api.readAccountReconciliation.mockResolvedValue(
      ok(
        view({
          openingBalance: null,
          movementsTotal: null,
          discrepancy: null,
          nonCalculableReason: 'MISSING_OPENING_BALANCE',
          availableResolutions: [],
          pendingCount: 0,
          pendingTransactions: [],
        }),
      ),
    );
    mount();

    expect(await screen.findByText(/ne peut pas être calculé/)).toBeTruthy();
    expect(screen.getByRole('status').textContent).toMatch(/solde d’ouverture/);
    expect(screen.queryByTestId('discrepancy')).toBeNull();
    expect(screen.queryByRole('button', { name: /Rapprocher/ })).toBeNull();
  });

  it('disables the match resolution unless the server offers it', async () => {
    mount();
    await screen.findByTestId('discrepancy');

    expect(
      (screen.getByRole('radio', { name: /Correspondance/ }) as HTMLInputElement).disabled,
    ).toBe(true);
  });

  it('requires an explicit confirmation before overriding', async () => {
    api.resolveAccountReconciliation.mockResolvedValue(
      ok(view({ reconciliationStatus: 'RECONCILED' })),
    );
    const onReconciled = mount();
    await screen.findByTestId('discrepancy');

    fireEvent.click(screen.getByRole('radio', { name: /Accepter l’écart/ }));
    const submit = screen.getByRole('button', { name: /Rapprocher/ }) as HTMLButtonElement;
    expect(submit.disabled).toBe(false);

    fireEvent.click(submit);
    expect((await screen.findByRole('alert')).textContent).toMatch(/Cochez la confirmation/);
    expect(api.resolveAccountReconciliation).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(submit);

    await waitFor(() => expect(onReconciled).toHaveBeenCalled());
    expect(api.resolveAccountReconciliation.mock.calls[0]?.[0].body).toEqual({
      snapshotId: account.valuation.snapshotId,
      snapshotVersion: 3,
      periodStart: '2026-09-01',
      resolution: 'OVERRIDE',
    });
  });

  it('states that an adjustment is neither income nor expense', async () => {
    mount();
    await screen.findByTestId('discrepancy');

    fireEvent.click(screen.getByRole('radio', { name: /Ajuster/ }));

    expect(screen.getByText(/ni un revenu ni une dépense/)).toBeTruthy();
  });

  it('asks for a reload when the snapshot version is stale', async () => {
    api.resolveAccountReconciliation.mockResolvedValue(failure(409, '/problems/stale-version'));
    mount();
    await screen.findByTestId('discrepancy');

    fireEvent.click(screen.getByRole('radio', { name: /Ajuster/ }));
    fireEvent.click(screen.getByRole('button', { name: /Rapprocher/ }));

    expect((await screen.findByRole('alert')).textContent).toMatch(/modifié/);
  });

  it('sends a stable idempotency key so a retry replays the first result', async () => {
    api.resolveAccountReconciliation.mockResolvedValue(failure(409, '/problems/stale-version'));
    mount();
    await screen.findByTestId('discrepancy');

    fireEvent.click(screen.getByRole('radio', { name: /Ajuster/ }));
    const submit = screen.getByRole('button', { name: /Rapprocher/ });
    fireEvent.click(submit);
    await screen.findByRole('alert');
    fireEvent.click(submit);
    await waitFor(() => expect(api.resolveAccountReconciliation).toHaveBeenCalledTimes(2));

    const keys = api.resolveAccountReconciliation.mock.calls.map(
      (call) => call[0].headers['Idempotency-Key'],
    );
    expect(keys[0]).toBe(`reconcile:${account.valuation.snapshotId}:3:2026-09-01:ADJUST`);
    expect(keys[1]).toBe(keys[0]);
  });

  it('reports a forbidden read', async () => {
    api.readAccountReconciliation.mockResolvedValue(failure(403, 'about:blank'));
    mount();

    expect((await screen.findByRole('alert')).textContent).toMatch(/droit/);
  });

  it('reports a failed read with a retry', async () => {
    api.readAccountReconciliation.mockResolvedValue(failure(500, 'about:blank'));
    mount();

    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeTruthy();
  });
});
