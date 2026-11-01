import type { AccountOverviewRow, PortfolioOverviewList } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { buildAccount } from '@/features/accounts/account-overview/overviewFixtures';
import { accountCardFixture } from './account-performance-cell/performanceFixtures';
import { AccountList } from './AccountList';

const api = vi.hoisted(() => ({
  listPortfolios: vi.fn(),
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

function list(
  items: ReturnType<typeof accountCardFixture>[],
  truncated = false,
): PortfolioOverviewList {
  return { items, truncated, total: {} as PortfolioOverviewList['total'] };
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

  it('adds no Performance column and reads no portfolios without an investment row', () => {
    renderList([livret]);

    expect(screen.queryByRole('columnheader', { name: 'Performance' })).toBeNull();
    expect(api.listPortfolios).not.toHaveBeenCalled();
  });

  it('adds a Performance column after the balance, with a quiet dash on other rows', async () => {
    api.listPortfolios.mockReturnValue(success(list([accountCardFixture({ accountId: 'p1' })])));
    renderList([livret, pea]);

    const headers = screen.getAllByRole('columnheader').map((header) => header.textContent);
    expect(headers.indexOf('Performance')).toBe(headers.indexOf('Solde observé') + 1);
    const peaRow = screen.getByRole('row', { name: /Mon PEA/ });
    await waitFor(() =>
      expect(
        within(peaRow).getByRole('link', { name: '+1,95 % depuis juillet 2026' }),
      ).toBeTruthy(),
    );
    expect(
      within(screen.getByRole('row', { name: /Livret A/ })).getByRole('img', {
        name: 'Sans objet',
      }),
    ).toBeTruthy();
    expect(api.listPortfolios).toHaveBeenCalledTimes(1);
  });

  it('blocks the performance values while the portfolios are read', () => {
    api.listPortfolios.mockReturnValue(new Promise(() => undefined));
    renderList([pea]);

    const row = screen.getByRole('row', { name: /Mon PEA/ });
    expect(
      row.querySelectorAll('td[data-label="Performance"] [data-skeleton]').length,
    ).toBeGreaterThan(0);
  });

  it('says the performance is unavailable when the read fails', async () => {
    api.listPortfolios.mockReturnValue(
      Promise.resolve({
        data: undefined,
        error: { type: '/problems/x', title: 'x', status: 500 },
        response: new Response('{}', { status: 500 }),
      }),
    );
    renderList([pea]);

    expect(await screen.findByText('Performance indisponible')).toBeTruthy();
  });

  it('states the 50-account limit instead of a failure when the list is truncated', async () => {
    api.listPortfolios.mockReturnValue(
      success(list([accountCardFixture({ accountId: 'x' })], true)),
    );
    renderList([pea]);

    expect(
      await screen.findByText('Performance non affichée : limite de 50 comptes atteinte'),
    ).toBeTruthy();
    expect(screen.queryByText('Performance indisponible')).toBeNull();
  });

  it('says unavailable, not limited, when an item is missing from a complete list', async () => {
    api.listPortfolios.mockReturnValue(success(list([accountCardFixture({ accountId: 'x' })])));
    renderList([pea]);

    expect(await screen.findByText('Performance indisponible')).toBeTruthy();
  });
});
