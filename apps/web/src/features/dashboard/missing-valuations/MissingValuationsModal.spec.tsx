import type { Account, NetWorthContribution } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MissingValuationsModal } from './MissingValuationsModal';

const api = vi.hoisted(() => ({
  readAccount: vi.fn(),
  recordAccountBalance: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const SAVINGS_ID = '00000000-0000-7000-8000-0000000000d1';
const PORTFOLIO_ID = '00000000-0000-7000-8000-0000000000d2';

function contribution(overrides: Partial<NetWorthContribution>): NetWorthContribution {
  return {
    accountId: SAVINGS_ID,
    label: 'Livret A',
    kind: 'SAVINGS',
    netWorthSign: 1,
    primaryGroupId: null,
    primaryGroupLabel: null,
    eligible: true,
    amount: null,
    signedAmount: null,
    quality: 'MISSING',
    ageDays: null,
    valuedOn: null,
    share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
    ...overrides,
  };
}

const account: Account = {
  id: SAVINGS_ID,
  label: 'Livret A',
  assetCode: 'EUR',
  kind: 'SAVINGS',
  productCode: null,
  productModelId: null,
  institution: null,
  maskedIdentifier: null,
  valuationMode: 'TRANSACTIONS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: true,
  openedOn: '2026-01-10',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  tagGroupIds: [],
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  valuation: {
    accountId: SAVINGS_ID,
    requestedOn: '2026-09-05',
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
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function renderModal(contributions: NetWorthContribution[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  const close = vi.fn();
  render(
    <QueryClientProvider client={queryClient}>
      <MissingValuationsModal close={close} contributions={contributions} />
    </QueryClientProvider>,
  );

  return { close, invalidate };
}

describe('MissingValuationsModal', () => {
  afterEach(() => {
    cleanup();
    vi.resetAllMocks();
  });

  it('lists only the eligible accounts without a valuation', () => {
    renderModal([
      contribution({}),
      contribution({ accountId: PORTFOLIO_ID, label: 'PEA', kind: 'PORTFOLIO' }),
      contribution({ accountId: 'current', label: 'Courant', quality: 'CURRENT' }),
      contribution({ accountId: 'closed', label: 'Ancien', eligible: false }),
    ]);

    expect(screen.getByRole('dialog', { name: '2 comptes sans valorisation' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Saisir le solde de « Livret A »' })).toBeTruthy();
    expect(
      screen.getByRole('link', { name: 'Voir le compte « Livret A »' }).getAttribute('href'),
    ).toBe(`/accounts/${SAVINGS_ID}`);
    // A portfolio is valued from its positions: no typed balance, only its page.
    expect(
      screen.getByRole('link', { name: 'Ouvrir le portefeuille « PEA »' }).getAttribute('href'),
    ).toBe(`/portfolios/${PORTFOLIO_ID}`);
    expect(screen.queryByText('Courant')).toBeNull();
    expect(screen.queryByText('Ancien')).toBeNull();
  });

  it('records the missing balance in place and refreshes the net worth', async () => {
    api.readAccount.mockReturnValue(success(account));
    api.recordAccountBalance.mockReturnValue(success(account.valuation, 201));
    const { invalidate } = renderModal([contribution({})]);

    fireEvent.click(screen.getByRole('button', { name: 'Saisir le solde de « Livret A »' }));
    fireEvent.change(await screen.findByLabelText('Montant observé'), {
      target: { value: '1250.40' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le solde' }));

    await waitFor(() => expect(api.recordAccountBalance).toHaveBeenCalledTimes(1));
    expect(api.recordAccountBalance).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { id: SAVINGS_ID },
        body: {
          asOf: '2026-09-05',
          amount: '1250.40',
          amountAssetCode: 'EUR',
          comment: null,
          version: null,
        },
      }),
    );
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['net-worth'] }));
    expect(await screen.findByRole('dialog', { name: '1 compte sans valorisation' })).toBeTruthy();
  });

  it('sends a portfolio-valued account to its page instead of a balance form', async () => {
    api.readAccount.mockReturnValue(success({ ...account, valuationMode: 'PORTFOLIO' }));
    renderModal([contribution({})]);

    fireEvent.click(screen.getByRole('button', { name: 'Saisir le solde de « Livret A »' }));

    expect(await screen.findByText(/ne se valorise pas par un solde saisi/)).toBeTruthy();
    expect(screen.queryByLabelText('Montant observé')).toBeNull();
  });
});
