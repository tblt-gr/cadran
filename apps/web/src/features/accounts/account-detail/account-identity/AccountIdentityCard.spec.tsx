import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountIdentityCard } from './AccountIdentityCard';

const api = vi.hoisted(() => ({
  listAccountGroups: vi.fn(),
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
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  tagGroupIds: [],
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
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
  });

  afterEach(() => {
    cleanup();
  });

  it('shows the account label, kind and institution', () => {
    renderCard(account);

    expect(screen.getByRole('heading', { name: 'Livret A Banque X' })).toBeTruthy();
    expect(screen.getByText('Livret ou épargne')).toBeTruthy();
    expect(screen.getByText('Banque X · ••4821')).toBeTruthy();
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
        perPage: 100,
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
      success({ items: [], page: 1, perPage: 100, total: 0 }),
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
});
