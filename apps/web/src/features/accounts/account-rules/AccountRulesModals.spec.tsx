import type { Account, AccountRuleOverridePage, AccountRules } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountRulesModals } from './AccountRulesModals';

const api = vi.hoisted(() => ({
  listAccountRuleOverrides: vi.fn(),
  readAccountRules: vi.fn(),
  recordAccountRuleOverride: vi.fn(),
  withdrawAccountRuleOverride: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const TODAY = '2026-09-03';

vi.mock('@/lib/businessDay', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/businessDay')>()),
  todayInBrowser: () => TODAY,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
  openedOn: '2020-01-15',
  closedOn: null,
} as Account;

const ceiling = {
  measurable: true,
  amount: { value: '22950', assetCode: 'EUR' },
  validFrom: '2025-04-25',
  validTo: null,
  verification: null,
  source: null,
  claim: null,
};

const claim = {
  overrideId: '00000000-0000-7000-8000-0000000000c1',
  reason: 'Confirmé par écrit.',
  authorId: '00000000-0000-7000-8000-000000000001',
  authorDisplayName: 'Owner',
  recordedAt: '2026-09-04T09:00:00+00:00',
};

function rules(withClaim: boolean): AccountRules {
  return {
    accountId: account.id,
    assetCode: 'EUR',
    productCode: 'FR_LIVRET_A',
    productModelId: null,
    origin: 'SYSTEM_CATALOG',
    asOf: TODAY,
    ceilings: [
      {
        kind: 'DEPOSIT_CEILING',
        basis: 'TOTAL_BALANCE',
        countsCreditedInterest: true,
        spansSeveralAccounts: false,
        effectiveLayer: withClaim ? 'OVERRIDE' : 'CATALOG',
        catalog: ceiling,
        inherited: null,
        override: withClaim ? { ...ceiling, claim } : null,
        check: {
          status: 'UNSETTLED',
          warning: false,
          measured: null,
          excess: null,
          unsettledReason: 'MISSING_VALUATION',
        },
      },
    ],
    rates: [],
    terms: [],
    unavailableRuleKinds: [],
    yieldReading: {
      contractual: null,
      assumption: null,
      assumptionReason: 'NOT_RECORDED',
      observed: null,
      observedReason: 'NO_RETURN_SERIES',
    },
  } as AccountRules;
}

function success(data: AccountRules | AccountRuleOverridePage) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function Harness() {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button onClick={() => setOpen(true)} type="button">
        Ouvrir les règles
      </button>
      {open ? (
        <AccountRulesModals
          account={account}
          close={() => setOpen(false)}
          onOverrideRecorded={vi.fn()}
          onOverrideWithdrawn={vi.fn()}
        />
      ) : null}
    </>
  );
}

function openRules() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <Harness />
    </QueryClientProvider>,
  );
  const opener = screen.getByRole('button', { name: 'Ouvrir les règles' });
  opener.focus();
  fireEvent.click(opener);

  return opener;
}

describe('AccountRulesModals', () => {
  beforeEach(() => {
    api.listAccountRuleOverrides.mockImplementation(() => success({ overrides: [] }));
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('refuses an empty override with field-level errors and sends nothing', async () => {
    api.readAccountRules.mockImplementation(() => success(rules(false)));
    openRules();

    await screen.findByRole('button', { name: 'Détails : Plafond de dépôt' });
    fireEvent.click(screen.getByRole('button', { name: 'Détails : Plafond de dépôt' }));
    fireEvent.click(screen.getByRole('button', { name: 'Déroger' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer la dérogation' }));

    expect(
      await screen.findByText(
        'La date de début est obligatoire et doit être un jour calendaire ISO 8601.',
      ),
    ).toBeTruthy();
    expect(
      screen.getByText('Le motif est obligatoire et ne dépasse pas 200 caractères.'),
    ).toBeTruthy();
    expect(api.recordAccountRuleOverride).not.toHaveBeenCalled();
  });

  it('returns focus to the rule action after the override modal closes, then to the opener', async () => {
    api.readAccountRules.mockImplementation(() => success(rules(false)));
    const opener = openRules();

    await screen.findByRole('button', { name: 'Détails : Plafond de dépôt' });
    fireEvent.click(screen.getByRole('button', { name: 'Détails : Plafond de dépôt' }));
    fireEvent.click(screen.getByRole('button', { name: 'Déroger' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Fermer' }));

    await screen.findByRole('button', { name: 'Détails : Plafond de dépôt' });
    const action = screen.getByRole('button', { name: 'Détails : Plafond de dépôt' });
    await waitFor(() => expect(document.activeElement).toBe(action));

    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    await waitFor(() => expect(document.activeElement).toBe(opener));
  });

  it('returns focus to the rule action after the withdrawal modal closes', async () => {
    api.readAccountRules.mockImplementation(() => success(rules(true)));
    openRules();

    await screen.findByRole('button', { name: 'Détails : Plafond de dépôt' });
    fireEvent.click(screen.getByRole('button', { name: 'Détails : Plafond de dépôt' }));
    fireEvent.click(screen.getByRole('button', { name: 'Retirer la dérogation' }));
    expect(await screen.findByText('Retirer cette dérogation')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    await screen.findByRole('button', { name: 'Détails : Plafond de dépôt' });
    const action = screen.getByRole('button', { name: 'Détails : Plafond de dépôt' });
    await waitFor(() => expect(document.activeElement).toBe(action));
    expect(api.withdrawAccountRuleOverride).not.toHaveBeenCalled();
  });
});
