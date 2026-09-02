import type { Product, ProductPage } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ProductCatalogPage } from './ProductCatalogPage';

const api = vi.hoisted(() => ({ listProducts: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const source = {
  publisher: 'Direction de l’information légale et administrative',
  title: 'Livret A',
  url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
  publishedOn: null,
  retrievedOn: '2026-08-22',
};

const livretA: Product = {
  code: 'FR_LIVRET_A',
  displayName: 'Livret A',
  jurisdiction: 'FR',
  accountKind: 'SAVINGS',
  wrapperKind: 'REGULATED_SAVINGS',
  yieldKind: 'REGULATED_RATE',
  yieldGuaranteed: true,
  defaultGroupCode: 'LIQUIDITY_SAVINGS',
  catalogVersion: 1,
  archivedAt: null,
  asOf: '2026-09-02',
  rules: [
    {
      kind: 'DEPOSIT_CEILING',
      valueType: 'AMOUNT',
      amount: { value: '22950', assetCode: 'EUR' },
      percentage: null,
      text: null,
      validFrom: '2025-04-25',
      validTo: null,
      verification: 'VERIFIED',
      verifiedOn: '2026-08-22',
      verifiedBy: 'cadran-maintainer',
      source,
    },
    {
      kind: 'ANNUAL_RATE',
      valueType: 'PERCENTAGE',
      amount: null,
      percentage: '1.7',
      text: null,
      validFrom: '2026-08-01',
      validTo: '2027-01-31',
      verification: 'STALE',
      verifiedOn: '2025-01-01',
      verifiedBy: 'cadran-maintainer',
      source,
    },
  ],
  unavailableRuleKinds: [],
};

const securitiesAccount: Product = {
  ...livretA,
  code: 'FR_CTO',
  displayName: 'Compte-titres ordinaire',
  accountKind: 'PORTFOLIO',
  wrapperKind: 'SECURITIES_ACCOUNT',
  yieldKind: 'MARKET',
  yieldGuaranteed: false,
  rules: [],
  unavailableRuleKinds: [],
};

function page(items: Product[]): ProductPage {
  return { items, page: 1, perPage: 25, total: items.length };
}

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response(null, { status }) });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <ProductCatalogPage />
    </QueryClientProvider>,
  );
}

function withoutNarrowSpaces(value: string) {
  return value.replace(/\s/g, ' ');
}

describe('ProductCatalogPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('announces that the catalogue is loading', () => {
    api.listProducts.mockReturnValue(new Promise(() => {}));

    renderPage();

    expect(screen.getByRole('status').textContent).toContain('Chargement du catalogue');
  });

  it('shows a regulatory value with its period, its verification and its official source', async () => {
    api.listProducts.mockReturnValue(success(page([livretA])));

    renderPage();

    const ceiling = await screen.findByRole('rowheader', { name: 'Plafond de dépôt' });
    const ceilingRow = ceiling.closest('tr');
    expect(withoutNarrowSpaces(ceilingRow?.textContent ?? '')).toContain('22 950 €');
    expect(ceilingRow?.textContent).toContain('Depuis le 25 avril 2025');
    expect(ceilingRow?.textContent).toContain('Vérifiée');

    const link = screen.getAllByRole('link', { name: 'Livret A' })[0];
    expect(link?.getAttribute('href')).toBe(
      'https://www.service-public.fr/particuliers/vosdroits/F2365',
    );
  });

  it('renders a rate as the percentage the source published', async () => {
    api.listProducts.mockReturnValue(success(page([livretA])));

    renderPage();

    const rate = await screen.findByRole('rowheader', { name: 'Taux annuel' });
    const rateRow = rate.closest('tr');
    expect(withoutNarrowSpaces(rateRow?.textContent ?? '')).toContain('1,7 %');
    expect(rateRow?.textContent).toContain('Du 1 août 2026 au 31 janvier 2027');
  });

  it('flags a value nobody has re-read as needing verification', async () => {
    api.listProducts.mockReturnValue(success(page([livretA])));

    renderPage();

    const rateRow = (await screen.findByRole('rowheader', { name: 'Taux annuel' })).closest('tr');
    // Not colour alone: the badge carries the words and an icon.
    expect(rateRow?.textContent).toContain('À revérifier');
  });

  it('reports a rule with no sourced period as unavailable rather than as zero', async () => {
    api.listProducts.mockReturnValue(
      success(page([{ ...livretA, rules: [], unavailableRuleKinds: ['ANNUAL_RATE'] }])),
    );

    renderPage();

    const rateRow = (await screen.findByRole('rowheader', { name: 'Taux annuel' })).closest('tr');
    expect(rateRow?.textContent).toContain('Non disponible au 2 septembre 2026');
    expect(rateRow?.textContent).not.toContain('0 %');
  });

  it('never announces a guaranteed yield on a market product', async () => {
    api.listProducts.mockReturnValue(success(page([securitiesAccount])));

    renderPage();

    expect(await screen.findByText('Rendement non garanti')).toBeTruthy();
    expect(screen.queryByText('Rendement garanti')).toBeNull();
  });

  it('resolves the catalogue against the business date the reader chooses', async () => {
    api.listProducts.mockReturnValue(success(page([livretA])));

    renderPage();
    await screen.findByRole('heading', { name: 'Livret A' });

    fireEvent.change(screen.getByLabelText('Date métier'), { target: { value: '2026-03-15' } });

    await waitFor(() => {
      expect(api.listProducts).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: { asOf: '2026-03-15', page: 1, perPage: 25 } }),
      );
    });
  });

  it('asks the reader to sign in again when the session has expired', async () => {
    api.listProducts.mockReturnValue(failure(401));

    renderPage();

    expect((await screen.findByRole('alert')).textContent).toContain('Session expirée');
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('offers a retry when the catalogue cannot be read', async () => {
    api.listProducts.mockReturnValue(failure(500));

    renderPage();

    expect((await screen.findByRole('alert')).textContent).toContain('Catalogue indisponible');
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('states that the catalogue holds nothing rather than showing an empty table', async () => {
    api.listProducts.mockReturnValue(success(page([])));

    renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucun produit' })).toBeTruthy();
  });
});
