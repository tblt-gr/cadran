import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { holdingsFixture, totalAccountSummary, unsplitAccount } from './holdingsFixture';
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
  beforeEach(() => window.history.replaceState({}, '', '/investments'));
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
    expect(screen.getByText('Vos actifs, tous comptes confondus.')).toBeTruthy();
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
    window.history.replaceState({}, '', '/investments?sort=NAME_ASC');
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
    expect(window.location.search).toBe('?sort=GAIN_DESC');
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

  it('switches to the account view through the view parameter and keeps the sort', async () => {
    window.history.replaceState({}, '', '/investments?sort=GAIN_DESC');
    api.readHoldings.mockImplementation(() => success(holdingsFixture()));
    renderPage();
    await screen.findByText('MSCI World');
    expect(screen.getByRole('button', { name: 'Par actif' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    expect(screen.queryByRole('table', { name: 'Positions par compte' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Par compte' }));
    expect(screen.getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
    expect(screen.queryByRole('table', { name: 'Positions par actif' })).toBeNull();
    expect(window.location.search).toBe('?sort=GAIN_DESC&view=account');
    expect(api.readHoldings).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Par actif' }));
    expect(window.location.search).toBe('?sort=GAIN_DESC');
    expect(screen.getByRole('table', { name: 'Positions par actif' })).toBeTruthy();
  });

  it('opens on the account view from the URL and shows the unsplit notice only by asset', async () => {
    window.history.replaceState({}, '', '/investments?view=account');
    api.readHoldings.mockImplementation(() =>
      success(holdingsFixture({ unsplitAccounts: [unsplitAccount] })),
    );
    renderPage();
    expect(await screen.findByRole('link', { name: 'CTO' })).toBeTruthy();
    expect(screen.queryByText(/est valorisé en total : ses titres/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Par actif' }));
    expect(screen.getByText(/est valorisé en total : ses titres/)).toBeTruthy();
  });

  it('keeps the view control reachable when only accounts valued as a total are held', async () => {
    api.readHoldings.mockImplementation(() =>
      success(
        holdingsFixture({
          items: [],
          warnings: [],
          unsplitAccounts: [unsplitAccount],
          accounts: [totalAccountSummary],
        }),
      ),
    );
    renderPage();
    expect(await screen.findByText('Aucune position détenue')).toBeTruthy();
    expect(screen.getByText(/Assurance vie est valorisé en total/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Par compte' }));
    expect(screen.getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
    expect(screen.getByText('Valorisation en total : titres non détaillés')).toBeTruthy();
  });

  it('shows the view control as real disabled buttons while loading', () => {
    window.history.replaceState({}, '', '/investments?view=account');
    api.readHoldings.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    const skeleton = screen.getByTestId('investments-skeleton');
    const byAccount = within(skeleton).getByRole('button', { name: 'Par compte' });
    expect((byAccount as HTMLButtonElement).disabled).toBe(true);
    expect(byAccount.getAttribute('aria-pressed')).toBe('true');
    expect(within(skeleton).getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
  });

  it('ignores an unknown sort parameter', async () => {
    window.history.replaceState({}, '', '/investments?sort=DROP');
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

  it('says no position is held while naming the account valued as a total', async () => {
    api.readHoldings.mockImplementation(() =>
      success(
        holdingsFixture({
          items: [],
          warnings: [],
          unsplitAccounts: [unsplitAccount],
          totals: {
            securitiesValue: '0',
            costBasis: null,
            unrealizedGain: null,
            performance: null,
            cash: '10.00',
            totalValue: '810.00',
            reason: null,
          },
        }),
      ),
    );
    renderPage();
    expect(await screen.findByText('Aucune position détenue')).toBeTruthy();
    expect(screen.getByText(/Assurance vie est valorisé en total/)).toBeTruthy();
  });
});
