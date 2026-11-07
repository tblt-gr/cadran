import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  allTotalHoldings,
  holdingsFixture,
  knownCostFigures,
  unsplitAccount,
} from './holdingsFixture';
import { expectExplanation } from '@/features/investments/explain-info/explanationDialog';
import { InvestmentsPage } from './InvestmentsPage';

const api = vi.hoisted(() => ({ readHoldings: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}
function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <InvestmentsPage />
    </QueryClientProvider>,
  );
}

describe('InvestmentsPage', () => {
  beforeEach(() => window.history.replaceState({}, '', '/investments?view=asset'));
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('keeps the header and tabs real behind one skeleton, then shows the holdings', async () => {
    let resolve: ((value: unknown) => void) | undefined;
    api.readHoldings.mockImplementation(
      () =>
        new Promise((done) => {
          resolve = done;
        }),
    );
    renderPage();
    expect(screen.queryByText('Vos actifs, tous comptes confondus.')).toBeNull();
    expect(screen.getByRole('link', { name: 'Positions' })).toBeTruthy();
    const skeleton = screen.getByTestId('investments-skeleton');
    expect(skeleton.querySelectorAll('[role="status"]')).toHaveLength(1);
    expect(within(skeleton).getByRole('table', { name: 'Positions par actif' })).toBeTruthy();

    resolve?.(await success(holdingsFixture()));
    expect(await screen.findByText('MSCI World')).toBeTruthy();
    expect(screen.queryByTestId('investments-skeleton')).toBeNull();
    expect(screen.getAllByText('MSCI World')).toHaveLength(1);
    expect(screen.getByText('Valorisations du 31 octobre 2026 au 3 novembre 2026')).toBeTruthy();
  });

  it('links the two tabs and marks Positions as the current page', async () => {
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByText('MSCI World');
    expect(screen.getByRole('link', { name: 'Positions' }).getAttribute('aria-current')).toBe(
      'page',
    );
    const instruments = screen.getByRole('link', { name: 'Instruments' });
    expect(instruments.getAttribute('href')).toBe('/investments/instruments');
    expect(instruments.getAttribute('aria-current')).toBeNull();
  });

  it('reads the order from the sort parameter and writes it back when a header is used', async () => {
    window.history.replaceState({}, '', '/investments?view=asset&sort=NAME_ASC');
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByText('MSCI World');
    expect(api.readHoldings.mock.calls[0]![0].query).toEqual({ sort: 'NAME_ASC' });

    fireEvent.click(
      within(screen.getByRole('columnheader', { name: /Plus-value latente/ })).getByRole('button'),
    );
    await waitFor(() =>
      expect(api.readHoldings.mock.calls.at(-1)![0].query).toEqual({ sort: 'GAIN_DESC' }),
    );
    expect(window.location.search).toBe('?view=asset&sort=GAIN_DESC');
  });

  it('keeps the rows and the opened details while another order is read', async () => {
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByText('MSCI World');
    fireEvent.click(screen.getAllByRole('button', { name: /Détail par compte/ })[0]!);
    expect(screen.getByRole('link', { name: 'PEA' })).toBeTruthy();

    api.readHoldings.mockImplementation(() => new Promise(() => undefined));
    fireEvent.click(
      within(screen.getByRole('columnheader', { name: /Plus-value latente/ })).getByRole('button'),
    );
    await waitFor(() => expect(api.readHoldings).toHaveBeenCalledTimes(2));
    expect(screen.queryByTestId('investments-skeleton')).toBeNull();
    expect(screen.getByRole('link', { name: 'PEA' })).toBeTruthy();
  });

  it('shows the known-cost figures as the gain and cost card values', async () => {
    const base = holdingsFixture();
    api.readHoldings.mockImplementation(() =>
      success(holdingsFixture({ totals: { ...base.totals, knownCost: knownCostFigures } })),
    );
    renderPage();
    await screen.findAllByRole('button', { name: /calculé sur une partie des positions/ });
    const card = screen.getByRole('heading', { name: 'Plus-value latente' }).closest('article')!;
    expect(within(card).getByText(/\+2\s454,65/)).toBeTruthy();
    expect(within(card).getByText('(+6,15 %)')).toBeTruthy();
    expect(within(card).queryByText(/Plus-value non calculable/)).toBeNull();
    const cost = screen.getByRole('heading', { name: 'Prix de revient' }).closest('article')!;
    expect(within(cost).getByText(/39\s887,16/)).toBeTruthy();
    await expectExplanation(
      within(card).getByRole('button', {
        name: 'Expliquer : Plus-value latente calculé sur une partie des positions',
      }),
      'Plus-value latente',
      /1 position sans coût exclue \(1\s239,00\s€\)/,
    );
  });

  it('puts the view control first, before the summary cards, loaded or not', async () => {
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    const before = (control: HTMLElement) =>
      control.compareDocumentPosition(screen.getByText('Valeur des titres')) &
      Node.DOCUMENT_POSITION_FOLLOWING;
    expect(before(screen.getByRole('group', { name: 'Affichage des positions' }))).toBeTruthy();
    await screen.findByText('MSCI World');
    expect(before(screen.getByRole('group', { name: 'Affichage des positions' }))).toBeTruthy();
  });

  it('opens on the account view by default, whatever an invalid view parameter says', async () => {
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    for (const url of ['/investments', '/investments?view=bogus']) {
      window.history.replaceState({}, '', url);
      const { unmount } = renderPage();
      expect(await screen.findByRole('link', { name: 'CTO' })).toBeTruthy();
      const segments = screen
        .getByRole('group', { name: 'Affichage des positions' })
        .querySelectorAll('button');
      expect([...segments].map((button) => button.textContent)).toEqual([
        'Par compte',
        'Par actif',
      ]);
      expect(screen.getByRole('button', { name: 'Par compte' }).getAttribute('aria-pressed')).toBe(
        'true',
      );
      expect(screen.queryByRole('table', { name: 'Positions par actif' })).toBeNull();
      unmount();
    }
  });

  it('switches views through the view parameter and keeps the sort', async () => {
    window.history.replaceState({}, '', '/investments?sort=GAIN_DESC');
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByRole('link', { name: 'CTO' });

    fireEvent.click(screen.getByRole('button', { name: 'Par actif' }));
    expect(screen.getByRole('table', { name: 'Positions par actif' })).toBeTruthy();
    expect(screen.queryByRole('table', { name: 'Positions par compte' })).toBeNull();
    expect(window.location.search).toBe('?sort=GAIN_DESC&view=asset');
    expect(api.readHoldings).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Par compte' }));
    expect(window.location.search).toBe('?sort=GAIN_DESC');
    expect(screen.getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
  });

  it('shows the unsplit notice only in the asset view', async () => {
    window.history.replaceState({}, '', '/investments');
    api.readHoldings.mockImplementation(() =>
      success(holdingsFixture({ unsplitAccounts: [unsplitAccount] })),
    );
    renderPage();
    expect(await screen.findByRole('link', { name: 'CTO' })).toBeTruthy();
    expect(screen.queryByText(/est valorisé en total : ses titres/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Par actif' }));
    expect(screen.getByText(/est valorisé en total : ses titres/)).toBeTruthy();
  });

  it('fills the cards and the donut by account when only accounts valued as a total are held', async () => {
    window.history.replaceState({}, '', '/investments');
    api.readHoldings.mockImplementation(() => success(allTotalHoldings()));
    renderPage();
    expect(await screen.findByText('Valorisation en total : titres non détaillés')).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
    const panel = screen.getByRole('region', { name: 'Synthèse des positions' });
    expect(within(panel).getByText(/800,00/)).toBeTruthy();
    expect(
      within(panel).getByRole('button', { name: 'Expliquer : Plus-value latente non calculable' }),
    ).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Compte' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    const legend = screen.getByRole('table', { name: 'Répartition des titres' });
    expect(within(legend).getByText('Assurance vie')).toBeTruthy();
    expect(screen.queryByText('Aucune position détenue')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Par actif' }));
    expect(screen.queryByText('Aucune position détenue')).toBeNull();
    expect(screen.getByText(/Assurance vie est valorisé en total/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Par compte' })).toBeTruthy();
  });

  it('shows the view control as real disabled buttons while loading', () => {
    window.history.replaceState({}, '', '/investments');
    api.readHoldings.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    const skeleton = screen.getByTestId('investments-skeleton');
    const byAccount = within(skeleton).getByRole('button', { name: 'Par compte' });
    expect((byAccount as HTMLButtonElement).disabled).toBe(true);
    expect(byAccount.getAttribute('aria-pressed')).toBe('true');
    expect(within(skeleton).getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
  });

  it('ignores an unknown sort parameter', async () => {
    window.history.replaceState({}, '', '/investments?view=asset&sort=DROP');
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByText('MSCI World');
    expect(api.readHoldings.mock.calls[0]![0].query).toEqual({ sort: 'VALUE_DESC' });
  });

  it('keeps the header and tabs usable on error and retries', async () => {
    api.readHoldings.mockImplementationOnce(() =>
      Promise.resolve({ error: { code: 'x' }, response: new Response('', { status: 500 }) }),
    );
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    expect(await screen.findByText('Les positions sont indisponibles.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Instruments' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('MSCI World')).toBeTruthy();
  });

  it('offers to add an account when there is no investment account', async () => {
    api.readHoldings.mockImplementation(() =>
      success(
        holdingsFixture({
          assetCode: null,
          valuedFrom: null,
          valuedTo: null,
          quality: 'NOT_CALCULABLE',
          warnings: [],
          items: [],
          unsplitAccounts: [],
          totals: {
            securitiesValue: null,
            costBasis: null,
            unrealizedGain: null,
            performance: null,
            cash: null,
            totalValue: null,
            reason: 'NO_VALUATION',
            knownCost: null,
          },
        }),
      ),
    );
    renderPage();
    expect(await screen.findByText("Aucun compte d'investissement")).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ajouter un compte' }).getAttribute('href')).toBe(
      '/accounts',
    );
  });

  it('keeps only the notices when several accounts have no detail by asset', async () => {
    api.readHoldings.mockImplementation(() =>
      success(
        holdingsFixture({
          items: [],
          warnings: [],
          unsplitAccounts: [
            unsplitAccount,
            { ...unsplitAccount, accountId: 'second-account', label: 'PEA global' },
          ],
          totals: {
            securitiesValue: '0',
            costBasis: null,
            unrealizedGain: null,
            performance: null,
            cash: '10.00',
            totalValue: '810.00',
            reason: null,
            knownCost: null,
          },
        }),
      ),
    );
    renderPage();
    expect(await screen.findByText(/Assurance vie est valorisé en total/)).toBeTruthy();
    expect(screen.getByText(/PEA global est valorisé en total/)).toBeTruthy();
    expect(screen.queryByText('Aucune position détenue')).toBeNull();
  });

  it('keeps the empty-position panel when there is no account valued as a total', async () => {
    api.readHoldings.mockImplementation(() =>
      success(holdingsFixture({ items: [], unsplitAccounts: [] })),
    );
    renderPage();
    expect(await screen.findByText('Aucune position détenue')).toBeTruthy();
    expect(screen.queryByText(/est valorisé en total/)).toBeNull();
  });
});
