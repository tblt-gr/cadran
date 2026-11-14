import type { AccountOverviewRow } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { buildAccount } from '@/features/accounts/account-overview/overviewFixtures';
import { AccountList } from './AccountList';

const api = vi.hoisted(() => ({
  listProductModels: vi.fn(),
  listProducts: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

const handlers = {
  onArchive: vi.fn(),
  onEdit: vi.fn(),
  onMove: vi.fn(),
  onReconcile: vi.fn(),
  onRecordBalance: vi.fn(),
  onRules: vi.fn(),
};

function renderList(accounts: AccountOverviewRow[] | null) {
  api.listProducts.mockImplementation(() =>
    success({ items: [], page: 1, pageSize: 100, total: 0 }),
  );
  api.listProductModels.mockImplementation(() =>
    success({ items: [], page: 1, pageSize: 100, total: 0 }),
  );
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <AccountList accounts={accounts} label="Épargne" {...handlers} />
    </QueryClientProvider>,
  );
}

const livret = buildAccount('a1', 'Livret A', {
  institution: 'Banque Postale',
  maskedIdentifier: '4821',
});
const pea = buildAccount('p1', 'Mon PEA', {
  institution: 'Courtier',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AccountList', () => {
  it('writes the identity under the name and has no identity or share column', () => {
    renderList([livret]);

    const headers = screen.getAllByRole('columnheader').map((header) => header.textContent);
    expect(headers).toEqual(['Libellé', 'Solde observé', 'Actions']);
    expect(screen.queryByRole('columnheader', { name: /Part/ })).toBeNull();
    const row = screen.getByRole('row', { name: /Livret A/ });
    expect(within(row).getByText('Banque Postale · Livret ou épargne · ••4821')).toBeTruthy();
  });

  it('joins only the parts that are present', () => {
    renderList([buildAccount('a2', 'Compte', { institution: null, maskedIdentifier: null })]);

    expect(screen.getByText('Livret ou épargne')).toBeTruthy();
  });

  it('has no performance column and reads no portfolios, even with an investment row', () => {
    renderList([livret, pea]);

    expect(screen.queryByRole('columnheader', { name: 'Performance' })).toBeNull();
    const headers = screen.getAllByRole('columnheader').map((header) => header.textContent);
    expect(headers).toEqual(['Libellé', 'Solde observé', 'Actions']);
  });
});
