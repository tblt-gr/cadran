import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountIdentityCard } from './AccountIdentityCard';

const api = vi.hoisted(() => ({
  listAccountGroups: vi.fn(),
  listProducts: vi.fn(),
  listProductModels: vi.fn(),
  readAccountRules: vi.fn(),
  readProduct: vi.fn(),
  readProductModel: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response('{}', { status }) });
}

const missingValuation = {
  accountId: '00000000-0000-7000-8000-0000000000d1',
  requestedOn: '2026-09-10',
  asOf: null,
  amount: null,
  display: null,
  belowDisplayStep: false,
  basis: null,
  derivation: null,
  reason: null,
  source: null,
  ageDays: null,
  quality: 'MISSING' as const,
  reconciliationStatus: null,
  snapshotId: null,
  version: null,
};

const account: Account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  kind: 'SAVINGS',
  productCode: null,
  productModelId: null,
  institution: 'Banque X',
  maskedIdentifier: '4821',
  valuationMode: 'SNAPSHOTS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: true,
  openedOn: '2026-01-10',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  isPrimary: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  valuation: missingValuation,
};

function renderCard(target: Account) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountIdentityCard account={target} />
    </QueryClientProvider>,
  );
}

describe('AccountIdentityCard', () => {
  beforeEach(() => {
    api.readAccountRules.mockImplementation(() => failure(404));
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
    api.listProducts.mockImplementation(() =>
      success({
        items: [{ code: 'FR_LIVRET_A', displayName: 'Livret A' }],
        page: 1,
        pageSize: 100,
        total: 1,
      }),
    );
    api.listProductModels.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
  });

  afterEach(() => {
    cleanup();
  });

  it('shows the account name and masked identifier', () => {
    renderCard(account);

    expect(screen.getByRole('heading', { name: 'Livret A Banque X' })).toBeTruthy();
    expect(screen.getByText('Aucun groupe')).toBeTruthy();
    expect(screen.getByText('••4821')).toBeTruthy();
    expect(screen.queryByText('Identité')).toBeNull();
    expect(screen.queryByText(/Ouvert le/)).toBeNull();
  });

  it('writes one line with the institution and the product above the group', async () => {
    renderCard({ ...account, productCode: 'FR_LIVRET_A' });

    const line = await screen.findByText('Banque X · Livret A');
    const group = screen.getByText('Aucun groupe');
    expect(Boolean(line.compareDocumentPosition(group) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(
      true,
    );
    expect(screen.queryByText('Établissement')).toBeNull();
    expect(screen.queryByText('Type de produit')).toBeNull();
  });

  it('keeps only the parts that are present and no line when both are absent', async () => {
    const { unmount } = renderCard({ ...account, productCode: 'FR_LIVRET_A', institution: null });
    expect(await screen.findByText('Livret A')).toBeTruthy();
    unmount();

    renderCard({ ...account, institution: null });
    await vi.waitFor(() => expect(api.listProducts).toHaveBeenCalled());
    expect(screen.queryByText(/·/)).toBeNull();
    expect(screen.queryByText('Banque X')).toBeNull();
  });

  it('writes the same line on a portfolio page', async () => {
    const queryClient = new QueryClient();
    render(
      <QueryClientProvider client={queryClient}>
        <AccountIdentityCard account={{ ...account, productCode: 'FR_LIVRET_A' }} portfolio />
      </QueryClientProvider>,
    );

    expect(await screen.findByText('Banque X · Livret A')).toBeTruthy();
  });

  it('keeps the contractual rate without showing a balance-based interest estimate', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        assetCode: 'EUR',
        ceilings: [],
        rates: [
          {
            kind: 'ANNUAL_RATE',
            effectiveLayer: 'CATALOG',
            catalog: {
              guaranteed: true,
              application: 'MARGINAL',
              brackets: [{ percentage: '1.7', lowerBound: '0', upperBound: null }],
            },
            applied: { effectivePercentage: '1.7', interest: '123.45', unsettledReason: null },
          },
        ],
      }),
    );
    renderCard(account);
    expect(await screen.findByText(/1,70/)).toBeTruthy();
    expect(screen.queryByText('Taux dû au titulaire')).toBeNull();
    expect(screen.queryByText(/Sur le solde observé/)).toBeNull();
    expect(screen.queryByText(/123,45/)).toBeNull();
  });

  it('shows no status badge for an active account, only for an inactive one', () => {
    renderCard(account);

    expect(screen.queryByText('Actif')).toBeNull();
  });

  it('shows a missing-valuation state, not zero, when no dated source answers', () => {
    renderCard(account);

    expect(screen.getByText('Non calculable')).toBeTruthy();
  });

  it('reads the primary group label when the account has one', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({
        items: [{ id: '00000000-0000-7000-8000-0000000000c1', label: 'Épargne' }],
        page: 1,
        pageSize: 100,
        total: 1,
      }),
    );
    renderCard({ ...account, primaryGroupId: '00000000-0000-7000-8000-0000000000c1' });

    expect(await screen.findByText('Épargne')).toBeTruthy();
  });

  it('shows the group as unavailable, not as no group, when the group read fails', async () => {
    api.listAccountGroups.mockImplementation(() => failure(500));
    renderCard({ ...account, primaryGroupId: '00000000-0000-7000-8000-0000000000c1' });

    expect(
      await screen.findByText('Le groupe rattaché à ce compte n’a pas pu être lu.'),
    ).toBeTruthy();
    expect(screen.queryByText('Aucun groupe')).toBeNull();
  });

  it('shows the group as unavailable, not as no group, when it is not on the fetched page', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
    renderCard({ ...account, primaryGroupId: '00000000-0000-7000-8000-0000000000c1' });

    expect(
      await screen.findByText('Le groupe rattaché à ce compte n’a pas pu être lu.'),
    ).toBeTruthy();
    expect(screen.queryByText('Aucun groupe')).toBeNull();
  });

  it('stays linkable and readable for an archived account', () => {
    renderCard({
      ...account,
      status: 'ARCHIVED',
      archivedAt: '2026-09-15T10:00:00Z',
      editable: false,
    });

    expect(screen.getByText('Archivé')).toBeTruthy();
  });

  it('shows the cumulative contributions against the plan ceiling, never the balance', async () => {
    const rules = {
      accountId: account.id,
      assetCode: 'EUR',
      productCode: 'FR_PEA',
      productModelId: null,
      origin: 'SYSTEM_CATALOG',
      asOf: '2026-09-10',
      ceilings: [
        {
          kind: 'CONTRIBUTION_CEILING',
          basis: 'CONTRIBUTIONS',
          countsCreditedInterest: false,
          spansSeveralAccounts: false,
          effectiveLayer: 'CATALOG',
          catalog: { amount: { assetCode: 'EUR', value: '150000' } },
          inherited: null,
          override: null,
          check: {
            status: 'WITHIN',
            warning: false,
            measured: { assetCode: 'EUR', value: '6200' },
            excess: null,
            unsettledReason: null,
          },
        },
      ],
      rates: [],
      terms: [],
      unavailableRuleKinds: [],
    };
    api.readAccountRules.mockImplementation(() => success(rules));
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <AccountIdentityCard account={account} portfolio />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Versements cumulés')).toBeTruthy();
    const figure = screen.getByText('Versements cumulés').parentElement;
    expect(figure?.textContent?.replace(/\s/g, ' ')).toContain('6 200,00 € / 150 000,00 €');
    expect(screen.queryByText('Plafond dépassé')).toBeNull();
  });
});
